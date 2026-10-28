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
}

// Process handles an active subscriber's MO in one transaction: a point
// purchase or an answer becomes a pending charge, and keyword queries become
// message intents. Unknown P-number keywords receive a menu and never charge.
// Subscription, unsubscription, Predict-and-Win, open questions, and free-credit
// play are not ported and are left unprocessed.
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
	if product != "soka" || kind != "InteractiveResponse" {
		return ErrNotApplicable
	}
	fields, err := namedValues(payload)
	if err != nil {
		return fmt.Errorf("%w: invalid payload", ErrNeedsReview)
	}
	text := fields["CustomerResponse"]
	class := sokagame.Classify(text, fields["Reason"])
	switch class {
	case sokagame.Predict, sokagame.OpenQuestion, sokagame.Unsubscribe:
		return ErrNotApplicable
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
	var sub subscription
	err = tx.QueryRowContext(ctx, `SELECT ps.id, ps.product_id, ps.subscriber_id, ps.state, ps.billing_segment, ps.current_question_id, ps.answer_level
		FROM product_subscriptions ps JOIN products p ON p.id=ps.product_id JOIN subscribers s ON s.id=ps.subscriber_id
		WHERE p.code='soka' AND s.msisdn=? FOR UPDATE`, msisdn).
		Scan(&sub.id, &sub.productID, &sub.subscriberID, &sub.state, &sub.segment, &sub.questionID, &sub.level)
	charges := class == sokagame.Purchase || class == sokagame.Answer
	if errors.Is(err, sql.ErrNoRows) || (err == nil && sub.state != "ACTIVE") {
		// PHP subscribes or reactivates here; those flows are not ported. A
		// charge request without a subscription is surfaced for review.
		if charges || class == sokagame.PurchaseMenu {
			return fmt.Errorf("%w: subscription not found or inactive", ErrNeedsReview)
		}
		return ErrNotApplicable
	}
	if err != nil {
		return err
	}
	if charges && sub.segment == "UNVERIFIED" {
		return fmt.Errorf("%w: billing segment unverified", ErrNeedsReview)
	}
	interactionResult, err := tx.ExecContext(ctx, `INSERT INTO interactions
		(product_id, subscriber_id, external_request_id, interaction_kind, inbound_text, occurred_at)
		VALUES (?, ?, ?, 'MO', ?, UTC_TIMESTAMP(6))`, sub.productID, sub.subscriberID, requestID, text)
	if err != nil {
		return err
	}
	interactionID, err := interactionResult.LastInsertId()
	if err != nil {
		return err
	}
	message := func(kind, body string) error {
		_, err := tx.ExecContext(ctx, `INSERT INTO outbound_messages
			(product_id, subscriber_id, interaction_id, message_kind, body_text, delivery_state, source_event_key)
			VALUES (?, ?, ?, ?, ?, 'PENDING', ?)`, sub.productID, sub.subscriberID, interactionID, kind, body, sourceKey(callbackID))
		return err
	}
	switch class {
	case sokagame.PurchaseMenu:
		err = message("POINT_PURCHASE_MENU", purchaseMenu)
	case sokagame.Help:
		err = message(sokagame.KindHelp, sokagame.HelpMsg)
	case sokagame.OndoaInfo:
		err = message(sokagame.KindOndoa, sokagame.OndoaMsg)
	case sokagame.Points:
		var balance int64
		if err = tx.QueryRowContext(ctx, `SELECT COALESCE(SUM(delta_points),0) FROM point_ledger WHERE product_id=? AND subscriber_id=?`,
			sub.productID, sub.subscriberID).Scan(&balance); err == nil {
			err = message(sokagame.KindPoints, sokagame.PointsMessage(balance, msisdn))
		}
	case sokagame.Purchase:
		amount, _ := sokagame.PurchaseAmount(text)
		_, err = charge(ctx, tx, sub, interactionID, callbackID, "PU"+strconv.FormatInt(callbackID, 10)+msisdn, "POINT_PURCHASE", amount, offerFor(sub.segment), fields["TransactionId"])
	case sokagame.Answer:
		err = p.answer(ctx, tx, sub, interactionID, callbackID, msisdn, text, fields["TransactionId"], now)
	}
	if err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `INSERT INTO processed_events (consumer_name, callback_event_id) VALUES ('soka-point-mo', ?)`, callbackID); err != nil {
		return err
	}
	return tx.Commit()
}

// answer scores the reply against the current question now, as PHP does, and
// charges for it; the points are credited only when the charge succeeds.
func (p Processor) answer(ctx context.Context, tx *sql.Tx, sub subscription, interactionID, callbackID int64, msisdn, text, reference string, now time.Time) error {
	if !sub.questionID.Valid {
		return fmt.Errorf("%w: subscriber has no current question", ErrNeedsReview)
	}
	var answerKey sql.NullString
	var questionPoints int64
	if err := tx.QueryRowContext(ctx, `SELECT answer_key, points FROM questions WHERE id=? AND product_id=?`, sub.questionID.Int64, sub.productID).
		Scan(&answerKey, &questionPoints); err != nil {
		return err
	}
	var bonus int64
	err := tx.QueryRowContext(ctx, `SELECT bonus_points FROM answer_level_bonuses WHERE product_id=? AND bonus_date=? AND level=?`,
		sub.productID, sokagame.DarDate(now), sub.level+1).Scan(&bonus)
	if err != nil && !errors.Is(err, sql.ErrNoRows) {
		return err
	}
	result, points := sokagame.Score(text, answerKey.String, questionPoints, sokagame.IsGoldenHour(now), bonus)
	answerResult, err := tx.ExecContext(ctx, `INSERT INTO answers
		(interaction_id, question_id, product_id, subscriber_id, answer_text, result, answered_at, points_on_success)
		VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), ?)`, interactionID, sub.questionID.Int64, sub.productID, sub.subscriberID, text, result, points)
	if err != nil {
		return err
	}
	answerID, err := answerResult.LastInsertId()
	if err != nil {
		return err
	}
	attemptID, err := charge(ctx, tx, sub, interactionID, callbackID, strconv.FormatInt(callbackID, 10)+msisdn, "ANSWER_CHARGE", sokagame.AnswerCharge, offerFor(sub.segment), reference)
	if err != nil {
		return err
	}
	_, err = tx.ExecContext(ctx, `UPDATE answers SET payment_attempt_id=? WHERE id=?`, attemptID, answerID)
	return err
}

// charge records a pending payment attempt and the intent the guarded
// dispatcher submits.
func charge(ctx context.Context, tx *sql.Tx, sub subscription, interactionID, callbackID int64, requestID, kind string, amount int64, offer, reference string) (int64, error) {
	result, err := tx.ExecContext(ctx, `INSERT INTO payment_attempts
		(product_id, subscriber_id, interaction_id, external_request_id, payment_kind, channel, amount_minor, currency, state, requested_at)
		VALUES (?, ?, ?, ?, ?, 'SMS', ?, 'TZS', 'PENDING', UTC_TIMESTAMP(6))`,
		sub.productID, sub.subscriberID, interactionID, requestID, kind, amount)
	if err != nil {
		return 0, err
	}
	attemptID, err := result.LastInsertId()
	if err != nil {
		return 0, err
	}
	_, err = tx.ExecContext(ctx, `INSERT INTO charge_intents (payment_attempt_id, source_callback_event_id, offer_code, reference_id)
		VALUES (?, ?, ?, ?)`, attemptID, callbackID, offer, nullable(reference))
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
				Name  string `json:"name"`
				Value string `json:"value"`
			} `json:"data"`
		} `json:"requestParam"`
	}
	if err := json.Unmarshal(payload, &envelope); err != nil {
		return nil, err
	}
	fields := make(map[string]string, len(envelope.RequestParam.Data))
	for _, item := range envelope.RequestParam.Data {
		fields[item.Name] = item.Value
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
