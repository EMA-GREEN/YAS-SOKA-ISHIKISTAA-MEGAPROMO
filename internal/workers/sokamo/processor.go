package sokamo

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"strconv"
	"strings"

	"yas-modernization/internal/callbacks"
)

var ErrNotApplicable = errors.New("callback is not a Soka point-purchase MO")
var ErrNeedsReview = errors.New("Soka purchase needs customer review")

const purchaseMenu = "Kununua ALAMA tafadhali tuma,\nP300 Kwa Tsh300.\nP600 Kwa Tsh500.\nP1500 Kwa Tsh1,000.\nP2500 Kwa Tsh2,000.\nP10000 Kwa Tsh5,000.\n"

var purchaseAmounts = map[string]int64{"P300": 300, "P600": 500, "P1500": 1000, "P2500": 2000, "P10000": 5000}
var similarPurchase = regexp.MustCompile(`(?i)^P[0-9]+`)

type Processor struct{ DB *sql.DB }

// Process records an exact purchase MO and a charge intent in one transaction.
// Unknown P-number keywords receive a menu intent but never create a charge.
func (p Processor) Process(ctx context.Context, callbackID int64) error {
	if callbackID <= 0 {
		return fmt.Errorf("%w: invalid callback ID", ErrNeedsReview)
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
	var envelope struct {
		RequestParam struct {
			Data []struct {
				Name  string `json:"name"`
				Value string `json:"value"`
			} `json:"data"`
		} `json:"requestParam"`
	}
	if err := json.Unmarshal(payload, &envelope); err != nil {
		return fmt.Errorf("%w: invalid payload", ErrNeedsReview)
	}
	fields := make(map[string]string, len(envelope.RequestParam.Data))
	for _, item := range envelope.RequestParam.Data {
		fields[item.Name] = item.Value
	}
	keyword := strings.ToUpper(strings.TrimSpace(fields["CustomerResponse"]))
	amount, exact := purchaseAmounts[keyword]
	if !exact && !similarPurchase.MatchString(keyword) {
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
	var productID, subscriberID, subscriptionID int64
	var state, segment string
	err = tx.QueryRowContext(ctx, `SELECT ps.id, ps.product_id, ps.subscriber_id, ps.state, ps.billing_segment
		FROM product_subscriptions ps JOIN products p ON p.id=ps.product_id JOIN subscribers s ON s.id=ps.subscriber_id
		WHERE p.code='soka' AND s.msisdn=? FOR UPDATE`, msisdn).
		Scan(&subscriptionID, &productID, &subscriberID, &state, &segment)
	if errors.Is(err, sql.ErrNoRows) {
		return fmt.Errorf("%w: subscription not found", ErrNeedsReview)
	}
	if err != nil {
		return err
	}
	if state != "ACTIVE" || segment == "UNVERIFIED" {
		return fmt.Errorf("%w: subscription inactive or billing segment unverified", ErrNeedsReview)
	}
	interactionResult, err := tx.ExecContext(ctx, `INSERT INTO interactions
		(product_id, subscriber_id, external_request_id, interaction_kind, inbound_text, occurred_at)
		VALUES (?, ?, ?, 'MO', ?, UTC_TIMESTAMP(6))`, productID, subscriberID, requestID, fields["CustomerResponse"])
	if err != nil {
		return err
	}
	interactionID, err := interactionResult.LastInsertId()
	if err != nil {
		return err
	}
	if !exact {
		if _, err := tx.ExecContext(ctx, `INSERT INTO outbound_messages
			(product_id, subscriber_id, interaction_id, message_kind, body_text, delivery_state, source_event_key)
			VALUES (?, ?, ?, 'POINT_PURCHASE_MENU', ?, 'PENDING', ?)`, productID, subscriberID, interactionID, purchaseMenu, sourceKey(callbackID)); err != nil {
			return err
		}
	} else {
		offerCode := "017008600005"
		if segment == "BOOM" {
			offerCode = "017008600272"
		}
		paymentRequestID := "PU" + strconv.FormatInt(callbackID, 10) + msisdn
		paymentResult, err := tx.ExecContext(ctx, `INSERT INTO payment_attempts
			(product_id, subscriber_id, interaction_id, external_request_id, payment_kind, channel, amount_minor, currency, state, requested_at)
			VALUES (?, ?, ?, ?, 'POINT_PURCHASE', 'SMS', ?, 'TZS', 'PENDING', UTC_TIMESTAMP(6))`,
			productID, subscriberID, interactionID, paymentRequestID, amount)
		if err != nil {
			return err
		}
		paymentID, err := paymentResult.LastInsertId()
		if err != nil {
			return err
		}
		referenceID := nullable(fields["TransactionId"])
		if _, err := tx.ExecContext(ctx, `INSERT INTO charge_intents
			(payment_attempt_id, source_callback_event_id, offer_code, reference_id)
			VALUES (?, ?, ?, ?)`, paymentID, callbackID, offerCode, referenceID); err != nil {
			return err
		}
	}
	if _, err := tx.ExecContext(ctx, `INSERT INTO processed_events (consumer_name, callback_event_id) VALUES ('soka-point-mo', ?)`, callbackID); err != nil {
		return err
	}
	return tx.Commit()
}

func sourceKey(callbackID int64) string { return "soka-point-mo:" + strconv.FormatInt(callbackID, 10) }

func nullable(value string) any {
	if value == "" {
		return nil
	}
	return value
}
