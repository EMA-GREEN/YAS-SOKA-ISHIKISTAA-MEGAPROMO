package sokapoints

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"strconv"
	"strings"
	"time"

	"yas-modernization/internal/callbacks"
	"yas-modernization/internal/workers/sokagame"
)

var ErrNotApplicable = errors.New("callback is not a Soka payment this worker handles")
var ErrInvalid = errors.New("Soka payment cannot be reconciled")

// Tiers come from YAS-SOKA/config.php POINT_PURCHASE_MAP. Amounts are whole TZS.
var pointsForTZS = map[int64]int64{300: 300, 500: 600, 1000: 1500, 2000: 2500, 5000: 10000}

const purchaseMessage = "Hongera! Umefanikiwa kununua POINT %point,salio la point zako ni %totalpoints. Endelea kununua point ujiongezeee nafasi ya ushindi kwenye droo Tsh.5,000,000!"
const lowBalanceMessage = "Ndugu Mteja hujafanikiwa kununua Point, tafadhali angalia salio lako na ujaribu tena. Usipoteze nafasi ya Ushindi wa hadi Tsh.5,000,000!"
const errorMessage = "Ndugu Mteja, Tafadhali tuma jibu lako tena. Asante."

type Processor struct {
	DB *sql.DB
	// Now decides dated level bonuses; nil means time.Now.
	Now func() time.Time
	// Profile selects the product; nil means Soka.
	Profile *sokagame.Profile
}

func (p Processor) profile() *sokagame.Profile {
	if p.Profile != nil {
		return p.Profile
	}
	return sokagame.Soka
}

// result is a validated payment callback matched to its pending attempt.
type result struct {
	prof                                                   *sokagame.Profile
	callbackID, attemptID, productID, subscriberID, amount int64
	requestID, msisdn, status, billingSystem               string
	fields                                                 map[string]string
}

// Process settles a Soka point-purchase or answer charge. The outcome, any
// points, any follow-up charge intent, message intents, and the replay marker
// commit together. It never calls a charging or SMS provider.
func (p Processor) Process(ctx context.Context, callbackID int64) error {
	if callbackID <= 0 {
		return fmt.Errorf("%w: callback ID", ErrInvalid)
	}
	tx, err := p.DB.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	var product, requestID, kind, msisdn string
	var payload []byte
	err = tx.QueryRowContext(ctx, `SELECT product, external_request_id, event_kind, msisdn, payload_json
		FROM callback_events WHERE id=? FOR UPDATE`, callbackID).Scan(&product, &requestID, &kind, &msisdn, &payload)
	if errors.Is(err, sql.ErrNoRows) {
		return fmt.Errorf("%w: callback not found", ErrInvalid)
	}
	if err != nil {
		return err
	}
	prof := p.profile()
	if product != prof.Code || kind != "Payment" {
		return ErrNotApplicable
	}
	// PHP routes PU request IDs to the purchase branch; other Soka payments are
	// answer charges only when Go created the attempt.
	// PHP checks Predict-and-Win request IDs before the PU purchase branch.
	prediction := prof.Predict && sokagame.IsPredictRequest(requestID)
	purchase := !prediction && strings.Contains(requestID, "PU")
	paymentKind := "ANSWER_CHARGE"
	switch {
	case prediction:
		paymentKind = "PREDICTION"
	case purchase:
		paymentKind = "POINT_PURCHASE"
	}
	var processed int
	err = tx.QueryRowContext(ctx, `SELECT 1 FROM processed_events WHERE consumer_name=? AND callback_event_id=?`, prof.PaymentConsumer, callbackID).Scan(&processed)
	if err == nil {
		return nil
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return err
	}
	r := result{prof: prof, callbackID: callbackID, requestID: requestID, msisdn: msisdn}
	var currency, state, storedKind, subscriberMSISDN string
	err = tx.QueryRowContext(ctx, `SELECT pa.id, pa.product_id, pa.subscriber_id, pa.amount_minor, pa.currency, pa.state, pa.payment_kind, s.msisdn
		FROM payment_attempts pa JOIN products p ON p.id=pa.product_id JOIN subscribers s ON s.id=pa.subscriber_id
		WHERE p.code=? AND pa.external_request_id=? FOR UPDATE`, prof.Code, requestID).
		Scan(&r.attemptID, &r.productID, &r.subscriberID, &r.amount, &currency, &state, &storedKind, &subscriberMSISDN)
	if !purchase && (errors.Is(err, sql.ErrNoRows) || (err == nil && storedKind != paymentKind)) {
		return ErrNotApplicable
	}
	if errors.Is(err, sql.ErrNoRows) {
		return fmt.Errorf("%w: pending payment not found", ErrInvalid)
	}
	if err != nil {
		return err
	}
	event, err := callbacks.ParseJSON(product, payload)
	if err != nil || event.RequestID != requestID || event.MSISDN != msisdn {
		return fmt.Errorf("%w: callback identity", ErrInvalid)
	}
	if event.Payment.Status == "UNKNOWN" {
		return fmt.Errorf("%w: unknown payment status", ErrInvalid)
	}
	if r.fields, err = namedValues(payload); err != nil {
		return fmt.Errorf("%w: payload", ErrInvalid)
	}
	if subscriberMSISDN != msisdn || currency != "TZS" || storedKind != paymentKind || state != "PENDING" {
		return fmt.Errorf("%w: payment identity or state", ErrInvalid)
	}
	var points int64
	if purchase {
		var knownTier bool
		if points, knownTier = pointsForTZS[r.amount]; !knownTier {
			return fmt.Errorf("%w: unknown purchase tier", ErrInvalid)
		}
	}
	r.status, r.billingSystem = event.Payment.Status, r.fields["billingSystem"]
	if r.status == "SUCCESS" {
		charged, err := strconv.ParseInt(strings.TrimSpace(r.fields["ChargeAmount"]), 10, 64)
		if err != nil || charged != r.amount {
			return fmt.Errorf("%w: charge amount mismatch", ErrInvalid)
		}
		if strings.TrimSpace(r.fields["BillingId"]) == "" {
			return fmt.Errorf("%w: missing billing ID", ErrInvalid)
		}
	} else if reason := strings.ToLower(r.fields["Reason"]); strings.Contains(reason, "low balance") || strings.Contains(reason, "insufficient balance") {
		r.status = "LOW_BALANCE_FAILED"
	} else {
		r.status = "BILLING_ERROR"
	}
	if _, err := tx.ExecContext(ctx, `UPDATE payment_attempts SET state=?, provider_transaction_id=?, settled_at=UTC_TIMESTAMP(6) WHERE id=?`,
		r.status, nullable(r.fields["BillingId"], r.status == "SUCCESS"), r.attemptID); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `INSERT INTO payment_events (payment_attempt_id, callback_event_id, event_kind, provider_status, occurred_at)
		VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6))`, r.attemptID, callbackID, r.status, event.Payment.Status); err != nil {
		return err
	}
	switch {
	case prediction:
		err = settlePrediction(ctx, tx, r)
	case purchase:
		err = p.settlePurchase(ctx, tx, r, points)
	default:
		err = p.settleAnswer(ctx, tx, r)
	}
	if err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `INSERT INTO processed_events (consumer_name, callback_event_id) VALUES (?, ?)`, prof.PaymentConsumer, callbackID); err != nil {
		return err
	}
	return tx.Commit()
}

func (p Processor) settlePurchase(ctx context.Context, tx *sql.Tx, r result, points int64) error {
	message := errorMessage
	if r.status == "SUCCESS" {
		if err := lockSubscriber(ctx, tx, r.subscriberID); err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, `INSERT INTO point_ledger
			(product_id, subscriber_id, payment_attempt_id, delta_points, reason, source_event_key, occurred_at)
			VALUES (?, ?, ?, ?, 'POINT_PURCHASE', ?, UTC_TIMESTAMP(6))`, r.productID, r.subscriberID, r.attemptID, points, sourceKey(r.attemptID)); err != nil {
			return err
		}
		// Soka raises USER_LEVEL on every successful purchase as well as answers;
		// Ishikistaa never raises it.
		if r.prof.LevelOnPayment {
			if _, err := tx.ExecContext(ctx, `UPDATE product_subscriptions SET answer_level=answer_level+1 WHERE product_id=? AND subscriber_id=?`, r.productID, r.subscriberID); err != nil {
				return err
			}
		}
		balance, err := balance(ctx, tx, r)
		if err != nil {
			return err
		}
		message = strings.ReplaceAll(strings.ReplaceAll(purchaseMessage, "%point", strconv.FormatInt(points, 10)), "%totalpoints", strconv.FormatInt(balance, 10))
	} else if r.status == "LOW_BALANCE_FAILED" {
		message = lowBalanceMessage
	}
	return insertMessage(ctx, tx, r, "POINT_PURCHASE_RESULT", message, sourceKey(r.attemptID))
}

// settleAnswer follows the non-PU branches of SokaPaymentConsumers.php. Success
// credits the points fixed when the answer arrived and sends the next question;
// a low balance steps down the 99 → 45 → 30 fallback ladder; other failures ask
// the customer to answer again. PHP's delayed retry rows are not ported.
func (p Processor) settleAnswer(ctx context.Context, tx *sql.Tx, r result) error {
	var answerID, points, interactionID int64
	err := tx.QueryRowContext(ctx, `SELECT id, points_on_success, interaction_id FROM answers WHERE payment_attempt_id=? FOR UPDATE`, r.attemptID).
		Scan(&answerID, &points, &interactionID)
	if errors.Is(err, sql.ErrNoRows) {
		return fmt.Errorf("%w: answer for charge not found", ErrInvalid)
	}
	if err != nil {
		return err
	}
	answerKey := strconv.FormatInt(answerID, 10)
	switch r.status {
	case "SUCCESS":
		if err := lockSubscriber(ctx, tx, r.subscriberID); err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, `INSERT INTO point_ledger
			(product_id, subscriber_id, answer_id, payment_attempt_id, delta_points, reason, source_event_key, occurred_at)
			VALUES (?, ?, ?, ?, ?, 'ANSWER', ?, UTC_TIMESTAMP(6))`, r.productID, r.subscriberID, answerID, r.attemptID, points, "soka-answer:"+answerKey); err != nil {
			return err
		}
		var level int64
		if err := tx.QueryRowContext(ctx, `SELECT answer_level FROM product_subscriptions WHERE product_id=? AND subscriber_id=? FOR UPDATE`,
			r.productID, r.subscriberID).Scan(&level); err != nil {
			return err
		}
		next, question, err := nextQuestion(ctx, tx, r.productID)
		if err != nil {
			return err
		}
		levelUp := 0
		if r.prof.LevelOnPayment {
			levelUp = 1
		}
		if _, err := tx.ExecContext(ctx, `UPDATE product_subscriptions SET answer_level=answer_level+?, current_question_id=COALESCE(?, current_question_id)
			WHERE product_id=? AND subscriber_id=?`, levelUp, next, r.productID, r.subscriberID); err != nil {
			return err
		}
		total, err := balance(ctx, tx, r)
		if err != nil {
			return err
		}
		body := strings.Replace(r.prof.PointAppend, "%s", strconv.FormatInt(total, 10), 1)
		if next != nil {
			body = r.prof.QuestionMessage(total, question)
		}
		if err := insertMessage(ctx, tx, r, sokagame.KindQuestion, body, "soka-answer-question:"+answerKey); err != nil {
			return err
		}
		now := time.Now()
		if p.Now != nil {
			now = p.Now()
		}
		if !r.prof.LevelBonuses {
			return nil
		}
		var reward sql.NullString
		err = tx.QueryRowContext(ctx, `SELECT reward_message FROM answer_level_bonuses WHERE product_id=? AND bonus_date=? AND level=?`,
			r.productID, sokagame.DarDate(now), level+1).Scan(&reward)
		if err != nil && !errors.Is(err, sql.ErrNoRows) {
			return err
		}
		if strings.TrimSpace(reward.String) != "" {
			return insertMessage(ctx, tx, r, sokagame.KindReward, reward.String, "soka-answer-reward:"+answerKey)
		}
		return nil
	case "LOW_BALANCE_FAILED":
		if amount, prefix, ok := sokagame.Fallback(r.amount, r.billingSystem); ok {
			requestID := prefix + strconv.FormatInt(r.callbackID, 10) + r.msisdn
			result, err := tx.ExecContext(ctx, `INSERT INTO payment_attempts
				(product_id, subscriber_id, interaction_id, external_request_id, payment_kind, channel, amount_minor, currency, state, requested_at, fallback_of_attempt_id)
				VALUES (?, ?, ?, ?, 'ANSWER_CHARGE', 'SMS', ?, 'TZS', 'PENDING', UTC_TIMESTAMP(6), ?)`,
				r.productID, r.subscriberID, interactionID, requestID, amount, r.attemptID)
			if err != nil {
				return err
			}
			fallbackID, err := result.LastInsertId()
			if err != nil {
				return err
			}
			var reference sql.NullString
			if err := tx.QueryRowContext(ctx, `SELECT reference_id FROM charge_intents WHERE payment_attempt_id=?`, r.attemptID).Scan(&reference); err != nil && !errors.Is(err, sql.ErrNoRows) {
				return err
			}
			if _, err := tx.ExecContext(ctx, `INSERT INTO charge_intents (payment_attempt_id, source_callback_event_id, offer_code, reference_id) VALUES (?, ?, ?, ?)`,
				fallbackID, r.callbackID, r.prof.ChargeOffer, reference); err != nil {
				return err
			}
			_, err = tx.ExecContext(ctx, `UPDATE answers SET payment_attempt_id=? WHERE id=?`, fallbackID, answerID)
			return err
		}
		return insertMessage(ctx, tx, r, sokagame.KindLowBalance, r.prof.LowBalance, sourceKey(r.attemptID))
	default:
		return insertMessage(ctx, tx, r, sokagame.KindError, r.prof.ErrorMsg, sourceKey(r.attemptID))
	}
}

// settlePrediction follows PredictAndWin::handlePayment. Success confirms the
// prediction and lets the subscriber choose another fixture; any failure
// reopens the chosen fixture so they can send the score again. There is no
// fallback charge and no points.
func settlePrediction(ctx context.Context, tx *sql.Tx, r result) error {
	var submissionID, fixtureID, slot int64
	err := tx.QueryRowContext(ctx, `SELECT id, fixture_id, slot FROM predict_submissions WHERE payment_attempt_id=? FOR UPDATE`, r.attemptID).
		Scan(&submissionID, &fixtureID, &slot)
	if errors.Is(err, sql.ErrNoRows) {
		return fmt.Errorf("%w: prediction for charge not found", ErrInvalid)
	}
	if err != nil {
		return err
	}
	key := sourceKey(r.attemptID)
	if r.status == "SUCCESS" {
		if _, err := tx.ExecContext(ctx, `UPDATE predict_submissions SET status='CONFIRMED', confirmed_at=UTC_TIMESTAMP(6) WHERE id=?`, submissionID); err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, `UPDATE predict_sessions SET state='SUBMITTED', pending_submission_id=NULL, selected_slot=NULL, selected_fixture_id=NULL
			WHERE product_id=? AND subscriber_id=?`, r.productID, r.subscriberID); err != nil {
			return err
		}
		return insertMessage(ctx, tx, r, "PREDICT_RECEIVED", sokagame.PredictReceived, key)
	}
	status, kind, body := "PAYMENT_FAILED", "PREDICT_ERROR", sokagame.PredictError
	if r.status == "LOW_BALANCE_FAILED" {
		status, kind, body = "LOW_BALANCE_FAILED", "PREDICT_LOW_BALANCE", sokagame.PredictLowBalance
	}
	if _, err := tx.ExecContext(ctx, `UPDATE predict_submissions SET status=? WHERE id=?`, status, submissionID); err != nil {
		return err
	}
	// PHP only updates an existing session; an expired, deleted one stays gone.
	if _, err := tx.ExecContext(ctx, `UPDATE predict_sessions SET state='FIXTURE_CHOSEN', selected_slot=?, selected_fixture_id=?, pending_submission_id=NULL,
		expires_at=DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 24 HOUR) WHERE product_id=? AND subscriber_id=?`, slot, fixtureID, r.productID, r.subscriberID); err != nil {
		return err
	}
	return insertMessage(ctx, tx, r, kind, body, key)
}

// nextQuestion picks a random current question, as PHP picks a random ID in
// MIN_QN_ID..MAX_QN_ID. It returns nil when the product has none.
func nextQuestion(ctx context.Context, tx *sql.Tx, productID int64) (any, string, error) {
	var id int64
	var text string
	err := tx.QueryRowContext(ctx, `SELECT id, question_text FROM questions WHERE product_id=?
		AND (valid_from IS NULL OR valid_from <= UTC_TIMESTAMP(6)) AND (valid_until IS NULL OR valid_until >= UTC_TIMESTAMP(6))
		ORDER BY RAND() LIMIT 1`, productID).Scan(&id, &text)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, "", nil
	}
	return id, text, err
}

// lockSubscriber serializes balance calculation for concurrent payments.
func lockSubscriber(ctx context.Context, tx *sql.Tx, subscriberID int64) error {
	var msisdn string
	return tx.QueryRowContext(ctx, `SELECT msisdn FROM subscribers WHERE id=? FOR UPDATE`, subscriberID).Scan(&msisdn)
}

func balance(ctx context.Context, tx *sql.Tx, r result) (int64, error) {
	var total int64
	err := tx.QueryRowContext(ctx, `SELECT COALESCE(SUM(delta_points),0) FROM point_ledger WHERE product_id=? AND subscriber_id=?`, r.productID, r.subscriberID).Scan(&total)
	return total, err
}

func insertMessage(ctx context.Context, tx *sql.Tx, r result, kind, body, key string) error {
	_, err := tx.ExecContext(ctx, `INSERT INTO outbound_messages
		(product_id, subscriber_id, message_kind, body_text, delivery_state, source_event_key)
		VALUES (?, ?, ?, ?, 'PENDING', ?)`, r.productID, r.subscriberID, kind, body, key)
	return err
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

func sourceKey(attemptID int64) string {
	return "soka-point-payment:" + strconv.FormatInt(attemptID, 10)
}

func nullable(value string, keep bool) any {
	if keep {
		return value
	}
	return nil
}
