// Package sokaboom grants the periodic BOOM free answer, replacing
// BoomCustomerBaseLoad.php, BoomCustomerCreditAwardPublisher.php, and
// BoomCustomerCreditAwardConsumer.php.
package sokaboom

import (
	"context"
	"database/sql"
	"errors"
	"regexp"
	"strconv"

	"yas-modernization/internal/workers/sokagame"
)

// A round names one award run, such as a date. It is part of the
// idempotency key, so it must be chosen by the operator, not generated.
var validRound = regexp.MustCompile(`^[A-Za-z0-9_.:-]{1,40}$`)

// Award gives every BOOM subscription one BOOM credit and a notification for
// the round, in batches. Like PHP it sets the credit to 1 rather than adding
// one, and includes inactive subscribers. A subscription already awarded in
// the round is skipped, so a rerun or a crash part-way through is safe.
func Award(ctx context.Context, db *sql.DB, round string, batch int) (int, error) {
	if !validRound.MatchString(round) {
		return 0, errors.New("award round must be 1-40 letters, digits, or ._:-")
	}
	if batch < 1 {
		batch = 500
	}
	total := 0
	for {
		n, err := awardBatch(ctx, db, round, batch)
		total += n
		if err != nil || n == 0 {
			return total, err
		}
	}
}

func awardBatch(ctx context.Context, db *sql.DB, round string, batch int) (int, error) {
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		return 0, err
	}
	defer tx.Rollback()
	rows, err := tx.QueryContext(ctx, `SELECT ps.id, ps.product_id, ps.subscriber_id FROM product_subscriptions ps JOIN products p ON p.id=ps.product_id
		WHERE p.code='soka' AND ps.billing_segment='BOOM'
		AND NOT EXISTS (SELECT 1 FROM credit_awards ca WHERE ca.subscription_id=ps.id AND ca.award_kind='BOOM' AND ca.award_round=?)
		ORDER BY ps.id LIMIT ? FOR UPDATE SKIP LOCKED`, round, batch)
	if err != nil {
		return 0, err
	}
	type target struct{ id, productID, subscriberID int64 }
	var targets []target
	for rows.Next() {
		var t target
		if err := rows.Scan(&t.id, &t.productID, &t.subscriberID); err != nil {
			rows.Close()
			return 0, err
		}
		targets = append(targets, t)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return 0, err
	}
	for _, t := range targets {
		if _, err := tx.ExecContext(ctx, `INSERT INTO credit_awards (product_id, subscription_id, award_kind, award_round) VALUES (?, ?, 'BOOM', ?)`,
			t.productID, t.id, round); err != nil {
			return 0, err
		}
		if _, err := tx.ExecContext(ctx, `UPDATE product_subscriptions SET boom_credits=1 WHERE id=?`, t.id); err != nil {
			return 0, err
		}
		if _, err := tx.ExecContext(ctx, `INSERT INTO outbound_messages (product_id, subscriber_id, message_kind, body_text, delivery_state, source_event_key)
			VALUES (?, ?, ?, ?, 'PENDING', ?)`, t.productID, t.subscriberID, sokagame.KindBoomNotify, sokagame.BoomCreditNotify,
			"boom-award:"+round+":"+strconv.FormatInt(t.id, 10)); err != nil {
			return 0, err
		}
	}
	return len(targets), tx.Commit()
}
