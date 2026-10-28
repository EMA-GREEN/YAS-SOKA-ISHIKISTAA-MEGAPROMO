package sokapoints

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"strconv"
	"strings"

	"yas-modernization/internal/callbacks"
)

var ErrNotApplicable = errors.New("callback is not a Soka point purchase")
var ErrInvalid = errors.New("point purchase cannot be reconciled")

// Tiers come from YAS-SOKA/config.php POINT_PURCHASE_MAP. Amounts are whole TZS.
var pointsForTZS = map[int64]int64{300: 300, 500: 600, 1000: 1500, 2000: 2500, 5000: 10000}

const purchaseMessage = "Hongera! Umefanikiwa kununua POINT %point,salio la point zako ni %totalpoints. Endelea kununua point ujiongezeee nafasi ya ushindi kwenye droo Tsh.5,000,000!"
const lowBalanceMessage = "Ndugu Mteja hujafanikiwa kununua Point, tafadhali angalia salio lako na ujaribu tena. Usipoteze nafasi ya Ushindi wa hadi Tsh.5,000,000!"
const errorMessage = "Ndugu Mteja, Tafadhali tuma jibu lako tena. Asante."

type Processor struct{ DB *sql.DB }

// Process commits the payment outcome, point award, customer message intent, and
// replay marker together. It never calls a charging or SMS provider.
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
	if product != "soka" || kind != "Payment" || !strings.Contains(requestID, "PU") {
		return ErrNotApplicable
	}
	var processed int
	err = tx.QueryRowContext(ctx, `SELECT 1 FROM processed_events WHERE consumer_name='soka-point-purchase' AND callback_event_id=?`, callbackID).Scan(&processed)
	if err == nil {
		return nil
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return err
	}
	event, err := callbacks.ParseJSON(product, payload)
	if err != nil || event.RequestID != requestID || event.MSISDN != msisdn {
		return fmt.Errorf("%w: callback identity", ErrInvalid)
	}
	if event.Payment.Status == "UNKNOWN" {
		return fmt.Errorf("%w: unknown payment status", ErrInvalid)
	}
	var envelope struct {
		RequestParam struct {
			Data []struct {
				Name  string          `json:"name"`
				Value json.RawMessage `json:"value"`
			} `json:"data"`
		} `json:"requestParam"`
	}
	if err := json.Unmarshal(payload, &envelope); err != nil {
		return fmt.Errorf("%w: payload", ErrInvalid)
	}
	fields := make(map[string]string, len(envelope.RequestParam.Data))
	for _, item := range envelope.RequestParam.Data {
		var value string
		if err := json.Unmarshal(item.Value, &value); err != nil {
			value = string(item.Value)
		}
		fields[item.Name] = value
	}
	var attemptID, productID, subscriberID, amount int64
	var currency, state, paymentKind, subscriberMSISDN string
	err = tx.QueryRowContext(ctx, `SELECT pa.id, pa.product_id, pa.subscriber_id, pa.amount_minor, pa.currency, pa.state, pa.payment_kind, s.msisdn
		FROM payment_attempts pa JOIN products p ON p.id=pa.product_id JOIN subscribers s ON s.id=pa.subscriber_id
		WHERE p.code='soka' AND pa.external_request_id=? FOR UPDATE`, requestID).
		Scan(&attemptID, &productID, &subscriberID, &amount, &currency, &state, &paymentKind, &subscriberMSISDN)
	if errors.Is(err, sql.ErrNoRows) {
		return fmt.Errorf("%w: pending payment not found", ErrInvalid)
	}
	if err != nil {
		return err
	}
	if subscriberMSISDN != msisdn || currency != "TZS" || paymentKind != "POINT_PURCHASE" || state != "PENDING" {
		return fmt.Errorf("%w: payment identity or state", ErrInvalid)
	}
	points, knownTier := pointsForTZS[amount]
	if !knownTier {
		return fmt.Errorf("%w: unknown purchase tier", ErrInvalid)
	}
	status := event.Payment.Status
	if status == "SUCCESS" {
		charged, err := strconv.ParseInt(strings.TrimSpace(fields["ChargeAmount"]), 10, 64)
		if err != nil || charged != amount {
			return fmt.Errorf("%w: charge amount mismatch", ErrInvalid)
		}
		if strings.TrimSpace(fields["BillingId"]) == "" {
			return fmt.Errorf("%w: missing billing ID", ErrInvalid)
		}
	} else if strings.Contains(strings.ToLower(fields["Reason"]), "low balance") || strings.Contains(strings.ToLower(fields["Reason"]), "insufficient balance") {
		status = "LOW_BALANCE_FAILED"
	} else {
		status = "BILLING_ERROR"
	}
	if _, err := tx.ExecContext(ctx, `UPDATE payment_attempts SET state=?, provider_transaction_id=?, settled_at=UTC_TIMESTAMP(6) WHERE id=?`,
		status, nullable(fields["BillingId"], status == "SUCCESS"), attemptID); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `INSERT INTO payment_events (payment_attempt_id, callback_event_id, event_kind, provider_status, occurred_at)
		VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6))`, attemptID, callbackID, status, event.Payment.Status); err != nil {
		return err
	}
	message := errorMessage
	if status == "SUCCESS" {
		// Serialize balance calculation for concurrent purchases by this subscriber.
		var lockedMSISDN string
		if err := tx.QueryRowContext(ctx, `SELECT msisdn FROM subscribers WHERE id=? FOR UPDATE`, subscriberID).Scan(&lockedMSISDN); err != nil {
			return err
		}
		if _, err := tx.ExecContext(ctx, `INSERT INTO point_ledger
			(product_id, subscriber_id, payment_attempt_id, delta_points, reason, source_event_key, occurred_at)
			VALUES (?, ?, ?, ?, 'POINT_PURCHASE', ?, UTC_TIMESTAMP(6))`, productID, subscriberID, attemptID, points, sourceKey(attemptID)); err != nil {
			return err
		}
		var balance int64
		if err := tx.QueryRowContext(ctx, `SELECT COALESCE(SUM(delta_points),0) FROM point_ledger WHERE product_id=? AND subscriber_id=?`, productID, subscriberID).Scan(&balance); err != nil {
			return err
		}
		message = strings.ReplaceAll(strings.ReplaceAll(purchaseMessage, "%point", strconv.FormatInt(points, 10)), "%totalpoints", strconv.FormatInt(balance, 10))
	} else if status == "LOW_BALANCE_FAILED" {
		message = lowBalanceMessage
	}
	if _, err := tx.ExecContext(ctx, `INSERT INTO outbound_messages
		(product_id, subscriber_id, message_kind, body_text, delivery_state, source_event_key)
		VALUES (?, ?, 'POINT_PURCHASE_RESULT', ?, 'PENDING', ?)`, productID, subscriberID, message, sourceKey(attemptID)); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `INSERT INTO processed_events (consumer_name, callback_event_id) VALUES ('soka-point-purchase', ?)`, callbackID); err != nil {
		return err
	}
	return tx.Commit()
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
