package sokamo

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"strconv"
	"time"

	"yas-modernization/internal/callbacks"
	"yas-modernization/internal/workers/sokagame"
)

var ErrNotApplicable = errors.New("callback is not a Soka MO this worker handles")
var ErrNeedsReview = errors.New("Soka MO needs customer review")

const purchaseMenu = "Kununua ALAMA tafadhali tuma,\nP300 Kwa Tsh300.\nP600 Kwa Tsh500.\nP1500 Kwa Tsh1,000.\nP2500 Kwa Tsh2,000.\nP10000 Kwa Tsh5,000.\n"

type Processor struct {
	DB *sql.DB
	// Now decides golden hours and dated level bonuses; nil means time.Now.
	Now func() time.Time
}

type subscription struct {
	productID, subscriberID, id int64
	state, segment              string
	questionID                  sql.NullInt64
	level                       int64
	freeCredits, boomCredits    int64
}

// mo is one callback being processed inside its transaction.
type mo struct {
	tx                  *sql.Tx
	callbackID          int64
	msisdn, requestID   string
	fields              map[string]string
	now                 time.Time
	productID           int64
	subscriberID        int64
	interactionID       int64
	messageSeq          int
	interactionRecorded bool
}

// Process handles a Soka subscriber's MO, activation, or deactivation in one
// transaction, following the order of SokaMoConsumer.php. Charges become
// pending intents; replies and renewal-system calls become intents too.
// Predict-and-Win and open questions are not ported and are left unprocessed.
func (p Processor) Process(ctx context.Context, callbackID int64) error {
	if callbackID <= 0 {
		return fmt.Errorf("%w: invalid callback ID", ErrNeedsReview)
	}
	now := time.Now()
	if p.Now != nil {
		now = p.Now()
	}
	tx, err := p.DB.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	var product, requestID, kind, msisdn string
	var payload []byte
	err = tx.QueryRowContext(ctx, `SELECT product, external_request_id, event_kind, msisdn, payload_json FROM callback_events WHERE id=? FOR UPDATE`, callbackID).
		Scan(&product, &requestID, &kind, &msisdn, &payload)
	if errors.Is(err, sql.ErrNoRows) {
		return fmt.Errorf("%w: callback not found", ErrNeedsReview)
	}
	if err != nil {
		return err
	}
	if product != "soka" {
		return ErrNotApplicable
	}
	switch kind {
	case "InteractiveResponse", "Activation", "RequestSendSms", "DeActivation":
	case callbacks.KindMpushSubsInit:
		return p.mpush(ctx, tx, callbackID, msisdn, payload)
	default:
		return ErrNotApplicable
	}
	fields, err := namedValues(payload)
	if err != nil {
		return fmt.Errorf("%w: invalid payload", ErrNeedsReview)
	}
	if fields["OfferCode"] == sokagame.TestOfferCode {
		return ErrNotApplicable
	}
	// PHP records an activation as a subscription only when SubscriptionStatus
	// is A. Otherwise it stores an MO with no text, which the MO worker would
	// charge as an answer; Go ignores it.
	if (kind == "Activation" || kind == "RequestSendSms") && fields["SubscriptionStatus"] != "A" {
		return ErrNotApplicable
	}
	class := sokagame.Answer
	if kind == "InteractiveResponse" {
		class = sokagame.Classify(fields["CustomerResponse"], fields["Reason"])
	}
	var processed int
	err = tx.QueryRowContext(ctx, `SELECT 1 FROM processed_events WHERE consumer_name='soka-point-mo' AND callback_event_id=?`, callbackID).Scan(&processed)
	if err == nil {
		return nil
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return err
	}
	event, err := callbacks.ParseJSON(product, payload)
	if err != nil || event.RequestID != requestID || event.MSISDN != msisdn {
		return fmt.Errorf("%w: callback identity", ErrNeedsReview)
	}
	m := &mo{tx: tx, callbackID: callbackID, msisdn: msisdn, requestID: requestID, fields: fields, now: now}
	if err := tx.QueryRowContext(ctx, `SELECT id FROM products WHERE code='soka'`).Scan(&m.productID); err != nil {
		return err
	}
	sub, err := m.loadSubscription(ctx)
	if err != nil {
		return err
	}
	switch {
	case class == sokagame.Predict:
		err = p.predict(ctx, m, sub)
	case class == sokagame.OpenQuestion:
		// PHP sends the open question to any MSISDN before checking the
		// subscription; Go needs one to know the points and question.
		if sub == nil {
			return ErrNotApplicable
		}
		err = m.openQuestion(ctx, sub)
	case kind == "Activation" || kind == "RequestSendSms":
		err = p.subscribe(ctx, m, sub, sokagame.IsBoomTag(fields["CustomerType"]))
	case kind == "DeActivation":
		err = m.deactivate(ctx, sub)
	default:
		err = p.interactive(ctx, m, sub, class)
	}
	if err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `INSERT INTO processed_events (consumer_name, callback_event_id) VALUES ('soka-point-mo', ?)`, callbackID); err != nil {
		return err
	}
	return tx.Commit()
}

// interactive handles a customer's free-text MO. Keyword replies and
// unsubscription apply in any state, as in PHP's checks before the
// INACTIVE branch.
func (p Processor) interactive(ctx context.Context, m *mo, sub *subscription, class sokagame.Class) error {
	text := m.fields["CustomerResponse"]
	if sub == nil {
		// PHP creates the subscriber row here and invites them to send SOKA.
		// Activation itself comes from the provider's Activation callback.
		if _, err := m.createSubscription(ctx, "INACTIVE", "NORMAL", false, false); err != nil {
			return err
		}
		if err := m.recordInteraction(ctx, "MO", text); err != nil {
			return err
		}
		return m.message(ctx, sokagame.KindInactiveSub, sokagame.InactiveSub)
	}
	if err := m.recordInteraction(ctx, "MO", text); err != nil {
		return err
	}
	switch class {
	case sokagame.Help:
		return m.message(ctx, sokagame.KindHelp, sokagame.HelpMsg)
	case sokagame.OndoaInfo:
		return m.message(ctx, sokagame.KindOndoa, sokagame.OndoaMsg)
	case sokagame.Points:
		balance, err := m.balance(ctx)
		if err != nil {
			return err
		}
		return m.message(ctx, sokagame.KindPoints, sokagame.PointsMessage(balance, m.msisdn))
	case sokagame.Unsubscribe:
		return m.unsubscribe(ctx, sub)
	}
	if sub.state != "ACTIVE" {
		if sokagame.IsReactivation(text) {
			return p.reactivate(ctx, m, sub)
		}
		return m.message(ctx, sokagame.KindInactiveSub, sokagame.InactiveSub)
	}
	switch class {
	case sokagame.PurchaseMenu:
		return m.message(ctx, "POINT_PURCHASE_MENU", purchaseMenu)
	case sokagame.Purchase:
		if sub.segment == "UNVERIFIED" {
			return fmt.Errorf("%w: billing segment unverified", ErrNeedsReview)
		}
		amount, _ := sokagame.PurchaseAmount(text)
		_, err := m.charge(ctx, *sub, "PU"+strconv.FormatInt(m.callbackID, 10)+m.msisdn, "POINT_PURCHASE", amount, offerFor(sub.segment))
		return err
	}
	if sub.boomCredits > 0 || sub.freeCredits > 0 {
		return p.freeAnswer(ctx, m, *sub, text)
	}
	if sub.segment == "UNVERIFIED" {
		return fmt.Errorf("%w: billing segment unverified", ErrNeedsReview)
	}
	return p.answer(ctx, m, *sub, text)
}

// score looks up the current question and scores the answer at arrival time.
func (p Processor) score(ctx context.Context, m *mo, sub subscription, text string, withBonus bool) (string, int64, error) {
	if !sub.questionID.Valid {
		return "", 0, fmt.Errorf("%w: subscriber has no current question", ErrNeedsReview)
	}
	var answerKey sql.NullString
	var questionPoints int64
	if err := m.tx.QueryRowContext(ctx, `SELECT answer_key, points FROM questions WHERE id=? AND product_id=?`, sub.questionID.Int64, sub.productID).
		Scan(&answerKey, &questionPoints); err != nil {
		return "", 0, err
	}
	var bonus int64
	if withBonus {
		err := m.tx.QueryRowContext(ctx, `SELECT bonus_points FROM answer_level_bonuses WHERE product_id=? AND bonus_date=? AND level=?`,
			sub.productID, sokagame.DarDate(m.now), sub.level+1).Scan(&bonus)
		if err != nil && !errors.Is(err, sql.ErrNoRows) {
			return "", 0, err
		}
	}
	result, points := sokagame.Score(text, answerKey.String, questionPoints, sokagame.IsGoldenHour(m.now), bonus)
	return result, points, nil
}

func (m *mo) insertAnswer(ctx context.Context, sub subscription, text, result string, points int64) (int64, error) {
	res, err := m.tx.ExecContext(ctx, `INSERT INTO answers
		(interaction_id, question_id, product_id, subscriber_id, answer_text, result, answered_at, points_on_success)
		VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), ?)`, m.interactionID, sub.questionID.Int64, sub.productID, sub.subscriberID, text, result, points)
	if err != nil {
		return 0, err
	}
	return res.LastInsertId()
}

// answer scores the reply against the current question now, as PHP does, and
// charges for it; the points are credited only when the charge succeeds.
func (p Processor) answer(ctx context.Context, m *mo, sub subscription, text string) error {
	result, points, err := p.score(ctx, m, sub, text, true)
	if err != nil {
		return err
	}
	answerID, err := m.insertAnswer(ctx, sub, text, result, points)
	if err != nil {
		return err
	}
	attemptID, err := m.charge(ctx, sub, strconv.FormatInt(m.callbackID, 10)+m.msisdn, "ANSWER_CHARGE", sokagame.AnswerCharge, offerFor(sub.segment))
	if err != nil {
		return err
	}
	_, err = m.tx.ExecContext(ctx, `UPDATE answers SET payment_attempt_id=? WHERE id=?`, attemptID, answerID)
	return err
}

// freeAnswer spends a BOOM credit first, then a normal credit. The answer is
// credited at once, without a level bonus or level change, and the next
// question follows. PHP also sends a zero-amount BOOM usage charge to the
// provider; that is not ported. Unlike PHP, spending a BOOM credit does not
// also reduce the normal credit.
func (p Processor) freeAnswer(ctx context.Context, m *mo, sub subscription, text string) error {
	result, points, err := p.score(ctx, m, sub, text, false)
	if err != nil {
		return err
	}
	answerID, err := m.insertAnswer(ctx, sub, text, result, points)
	if err != nil {
		return err
	}
	if err := lockSubscriber(ctx, m.tx, sub.subscriberID); err != nil {
		return err
	}
	if _, err := m.tx.ExecContext(ctx, `INSERT INTO point_ledger
		(product_id, subscriber_id, answer_id, delta_points, reason, source_event_key, occurred_at)
		VALUES (?, ?, ?, ?, 'FREE_ANSWER', ?, UTC_TIMESTAMP(6))`, sub.productID, sub.subscriberID, answerID, points, "soka-answer:"+strconv.FormatInt(answerID, 10)); err != nil {
		return err
	}
	column, remaining := "boom_credits", sub.boomCredits-1
	if sub.boomCredits == 0 {
		column, remaining = "free_credits", sub.freeCredits-1
	}
	next, question, err := nextQuestion(ctx, m.tx, sub.productID)
	if err != nil {
		return err
	}
	if _, err := m.tx.ExecContext(ctx, `UPDATE product_subscriptions SET `+column+`=`+column+`-1, current_question_id=COALESCE(?, current_question_id) WHERE id=?`, next, sub.id); err != nil {
		return err
	}
	total, err := m.balance(ctx)
	if err != nil {
		return err
	}
	return m.message(ctx, sokagame.KindQuestion, sokagame.CreditQuestion(total, remaining, question))
}

// charge records a pending payment attempt and the intent the guarded
// dispatcher submits.
func (m *mo) charge(ctx context.Context, sub subscription, requestID, kind string, amount int64, offer string) (int64, error) {
	result, err := m.tx.ExecContext(ctx, `INSERT INTO payment_attempts
		(product_id, subscriber_id, interaction_id, external_request_id, payment_kind, channel, amount_minor, currency, state, requested_at)
		VALUES (?, ?, ?, ?, ?, 'SMS', ?, 'TZS', 'PENDING', UTC_TIMESTAMP(6))`,
		sub.productID, sub.subscriberID, m.interactionID, requestID, kind, amount)
	if err != nil {
		return 0, err
	}
	attemptID, err := result.LastInsertId()
	if err != nil {
		return 0, err
	}
	_, err = m.tx.ExecContext(ctx, `INSERT INTO charge_intents (payment_attempt_id, source_callback_event_id, offer_code, reference_id)
		VALUES (?, ?, ?, ?)`, attemptID, m.callbackID, offer, nullable(m.fields["TransactionId"]))
	return attemptID, err
}

func offerFor(segment string) string {
	if segment == "BOOM" {
		return "017008600272"
	}
	return sokagame.NormalOffer
}

func namedValues(payload []byte) (map[string]string, error) {
	var envelope struct {
		RequestParam struct {
			Data []struct {
				Name  string          `json:"name"`
				Value json.RawMessage `json:"value"`
			} `json:"data"`
		} `json:"requestParam"`
	}
	if err := json.Unmarshal(payload, &envelope); err != nil {
		return nil, err
	}
	fields := make(map[string]string, len(envelope.RequestParam.Data))
	for _, item := range envelope.RequestParam.Data {
		var value string
		if err := json.Unmarshal(item.Value, &value); err != nil {
			value = string(item.Value)
		}
		fields[item.Name] = value
	}
	return fields, nil
}

func sourceKey(callbackID int64) string { return "soka-point-mo:" + strconv.FormatInt(callbackID, 10) }

func nullable(value string) any {
	if value == "" {
		return nil
	}
	return value
}
