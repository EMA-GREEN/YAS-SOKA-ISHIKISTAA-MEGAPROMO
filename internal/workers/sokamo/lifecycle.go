package sokamo

import (
	"context"
	"database/sql"
	"errors"
	"strconv"

	"yas-modernization/internal/workers/sokagame"
)

// Renewal-system endpoints PHP's sendRenewal posts to, in order.
var renewalTargets = []string{"MANAGE_SUBSCRIPTION", "SUBS_MANAGE"}

func (m *mo) loadSubscription(ctx context.Context) (*subscription, error) {
	var sub subscription
	err := m.tx.QueryRowContext(ctx, `SELECT ps.id, ps.product_id, ps.subscriber_id, ps.state, ps.billing_segment, ps.current_question_id,
		ps.answer_level, ps.free_credits, ps.boom_credits
		FROM product_subscriptions ps JOIN subscribers s ON s.id=ps.subscriber_id
		WHERE ps.product_id=? AND s.msisdn=? FOR UPDATE`, m.productID, m.msisdn).
		Scan(&sub.id, &sub.productID, &sub.subscriberID, &sub.state, &sub.segment, &sub.questionID, &sub.level, &sub.freeCredits, &sub.boomCredits)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	m.subscriberID = sub.subscriberID
	return &sub, nil
}

func (m *mo) ensureSubscriber(ctx context.Context) error {
	if m.subscriberID != 0 {
		return nil
	}
	res, err := m.tx.ExecContext(ctx, `INSERT INTO subscribers (msisdn) VALUES (?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)`, m.msisdn)
	if err != nil {
		return err
	}
	m.subscriberID, err = res.LastInsertId()
	return err
}

func (m *mo) createSubscription(ctx context.Context, state, segment string, boom, staff bool) (*subscription, error) {
	if err := m.ensureSubscriber(ctx); err != nil {
		return nil, err
	}
	boomCredits := 0
	if boom {
		boomCredits = 1
	}
	res, err := m.tx.ExecContext(ctx, `INSERT INTO product_subscriptions
		(product_id, subscriber_id, state, billing_segment, started_at, boom_credits, do_not_disturb)
		VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), ?, ?)`, m.productID, m.subscriberID, state, segment, boomCredits, staff)
	if err != nil {
		return nil, err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}
	return &subscription{id: id, productID: m.productID, subscriberID: m.subscriberID, state: state, segment: segment, boomCredits: int64(boomCredits)}, nil
}

func (m *mo) recordInteraction(ctx context.Context, kind, text string) error {
	if m.interactionRecorded {
		return nil
	}
	if err := m.ensureSubscriber(ctx); err != nil {
		return err
	}
	res, err := m.tx.ExecContext(ctx, `INSERT INTO interactions
		(product_id, subscriber_id, external_request_id, interaction_kind, inbound_text, occurred_at)
		VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6))`, m.productID, m.subscriberID, m.requestID, kind, text)
	if err != nil {
		return err
	}
	m.interactionID, err = res.LastInsertId()
	m.interactionRecorded = err == nil
	return err
}

// message records an SMS intent. The first message of a callback keeps the
// plain source key; later ones are numbered so each is stored once.
func (m *mo) message(ctx context.Context, kind, body string) error {
	key := sourceKey(m.callbackID)
	if m.messageSeq > 0 {
		key += ":" + strconv.Itoa(m.messageSeq)
	}
	m.messageSeq++
	var interaction any
	if m.interactionRecorded {
		interaction = m.interactionID
	}
	_, err := m.tx.ExecContext(ctx, `INSERT INTO outbound_messages
		(product_id, subscriber_id, interaction_id, message_kind, body_text, delivery_state, source_event_key)
		VALUES (?, ?, ?, ?, ?, 'PENDING', ?)`, m.productID, m.subscriberID, interaction, kind, body, key)
	return err
}

func (m *mo) balance(ctx context.Context) (int64, error) {
	var total int64
	err := m.tx.QueryRowContext(ctx, `SELECT COALESCE(SUM(delta_points),0) FROM point_ledger WHERE product_id=? AND subscriber_id=?`,
		m.productID, m.subscriberID).Scan(&total)
	return total, err
}

// renewal records one renewal-system request per endpoint for the guarded
// renewal dispatcher.
func (m *mo) renewal(ctx context.Context, activity string) error {
	if err := m.ensureSubscriber(ctx); err != nil {
		return err
	}
	for _, target := range renewalTargets {
		if _, err := m.tx.ExecContext(ctx, `INSERT INTO renewal_requests (product_id, subscriber_id, source_callback_event_id, activity_type, target)
			VALUES (?, ?, ?, ?, ?)`, m.productID, m.subscriberID, m.callbackID, activity, target); err != nil {
			return err
		}
	}
	return nil
}

func (m *mo) event(ctx context.Context, sub *subscription, kind string) error {
	_, err := m.tx.ExecContext(ctx, `INSERT INTO subscription_events (subscription_id, product_id, subscriber_id, event_kind, effective_at, source_event_key)
		VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), ?)`, sub.id, sub.productID, sub.subscriberID, kind, "callback:"+strconv.FormatInt(m.callbackID, 10))
	return err
}

// setQuestion makes a random current question the subscriber's next one and
// returns its text, or "" when the product has none.
func (m *mo) setQuestion(ctx context.Context, sub *subscription) (string, error) {
	next, question, err := nextQuestion(ctx, m.tx, m.productID)
	if err != nil || next == nil {
		return "", err
	}
	_, err = m.tx.ExecContext(ctx, `UPDATE product_subscriptions SET current_question_id=? WHERE id=?`, next, sub.id)
	return question, err
}

// subscribe follows the SUBSCRIPTION branch: the provider confirmed an
// activation. Non-staff subscriptions are also reported to the renewal system.
func (p Processor) subscribe(ctx context.Context, m *mo, sub *subscription, boom bool) error {
	staff := sokagame.IsStaff(m.msisdn)
	if !staff {
		if err := m.renewal(ctx, "SUBSCRIPTION"); err != nil {
			return err
		}
	}
	text := "SOKA"
	if boom {
		text = "BOOM_CUSTOMER-SOKA"
	}
	var points int64
	switch {
	case sub == nil:
		var err error
		segment := "NORMAL"
		if boom {
			segment = "BOOM"
		}
		if sub, err = m.createSubscription(ctx, "ACTIVE", segment, boom, staff); err != nil {
			return err
		}
		if err := m.event(ctx, sub, "SUBSCRIBED"); err != nil {
			return err
		}
	case sub.state == "ACTIVE":
		// Already active: PHP just sends a fresh question without the welcome.
		if err := m.recordInteraction(ctx, "ACTIVATION", text); err != nil {
			return err
		}
		question, err := m.setQuestion(ctx, sub)
		if err != nil || question == "" {
			return err
		}
		return m.message(ctx, sokagame.KindQuestion, question+".")
	default:
		segment, credits := "NORMAL", sub.boomCredits
		if boom {
			segment, credits = "BOOM", 1
		}
		if _, err := m.tx.ExecContext(ctx, `UPDATE product_subscriptions SET state='ACTIVE', billing_segment=?, boom_credits=?, ended_at=NULL WHERE id=?`,
			segment, credits, sub.id); err != nil {
			return err
		}
		if err := m.event(ctx, sub, "REACTIVATED"); err != nil {
			return err
		}
		var err error
		if points, err = m.balance(ctx); err != nil {
			return err
		}
	}
	if err := m.recordInteraction(ctx, "ACTIVATION", text); err != nil {
		return err
	}
	question, err := m.setQuestion(ctx, sub)
	if err != nil {
		return err
	}
	if question != "" {
		if err := m.message(ctx, sokagame.KindQuestion, sokagame.WelcomeQuestion(points, question)); err != nil {
			return err
		}
	}
	if boom {
		return m.message(ctx, sokagame.KindBoomNotify, sokagame.BoomCreditNotify)
	}
	return nil
}

// reactivate handles SOKA from an inactive subscriber.
func (p Processor) reactivate(ctx context.Context, m *mo, sub *subscription) error {
	if !sokagame.IsStaff(m.msisdn) {
		if err := m.renewal(ctx, "SUBSCRIPTION"); err != nil {
			return err
		}
	}
	if _, err := m.tx.ExecContext(ctx, `UPDATE product_subscriptions SET state='ACTIVE', ended_at=NULL WHERE id=?`, sub.id); err != nil {
		return err
	}
	if err := m.event(ctx, sub, "REACTIVATED"); err != nil {
		return err
	}
	question, err := m.setQuestion(ctx, sub)
	if err != nil || question == "" {
		return err
	}
	points, err := m.balance(ctx)
	if err != nil {
		return err
	}
	return m.message(ctx, sokagame.KindQuestion, sokagame.ReactivationQuestion(points, question))
}

// deactivate follows the UN_SUBSCRIPTION branch for the provider's
// DeActivation callback. PHP reports it to the renewal system even for staff.
func (m *mo) deactivate(ctx context.Context, sub *subscription) error {
	if err := m.renewal(ctx, "UNSUBSCRIPTION"); err != nil {
		return err
	}
	if err := m.recordInteraction(ctx, "DEACTIVATION", "ONDOA SOKA"); err != nil {
		return err
	}
	if sub == nil || sub.state != "ACTIVE" {
		return m.message(ctx, sokagame.KindNonCustomer, sokagame.NonCustomer)
	}
	return m.end(ctx, sub)
}

// unsubscribe handles ONDOA SOKA or STOP from the customer.
func (m *mo) unsubscribe(ctx context.Context, sub *subscription) error {
	if err := m.renewal(ctx, "UNSUBSCRIPTION"); err != nil {
		return err
	}
	return m.end(ctx, sub)
}

// end makes the subscription inactive and resets the level, sending nothing,
// as PHP does.
func (m *mo) end(ctx context.Context, sub *subscription) error {
	if _, err := m.tx.ExecContext(ctx, `UPDATE product_subscriptions SET state='INACTIVE', answer_level=0, ended_at=UTC_TIMESTAMP(6) WHERE id=?`, sub.id); err != nil {
		return err
	}
	return m.event(ctx, sub, "UNSUBSCRIBED")
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
