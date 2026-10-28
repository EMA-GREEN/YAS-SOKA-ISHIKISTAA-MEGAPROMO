// Package sokaopen sends scheduled Soka open questions, replacing
// SokaOpenQuestionScheduler.php, SokaOpenQuestionPubliser.php, and
// SokaOpenQuestionConsumer.php. Each due schedule becomes one delivery and one
// SMS intent per eligible subscription, created in resumable batches.
package sokaopen

import (
	"context"
	"database/sql"
	"errors"
	"strconv"
	"time"

	"yas-modernization/internal/workers/sokagame"
)

type Scheduler struct {
	DB *sql.DB
	// Now is the clock for due schedules and audience windows; nil means time.Now.
	Now   func() time.Time
	Batch int
}

var darEsSalaam = func() *time.Location {
	if loc, err := time.LoadLocation("Africa/Dar_es_Salaam"); err == nil {
		return loc
	}
	return time.FixedZone("EAT", 3*60*60)
}()

// Last activity is PHP's LAST_CHARGING, RECORD_DATE, and REACT_DATE. PHP
// selects a subscriber when any one of them falls in the window.
const lastCharge = `(SELECT MAX(pa.settled_at) FROM payment_attempts pa WHERE pa.product_id=ps.product_id AND pa.subscriber_id=ps.subscriber_id AND pa.state='SUCCESS')`
const lastReactivation = `(SELECT MAX(se.effective_at) FROM subscription_events se WHERE se.subscription_id=ps.id AND se.event_kind='REACTIVATED')`

// audienceFilter returns the extra condition and its two bound times. PLAYING
// is activity in the last 7 days; TOP is activity between 59 and 7 days ago.
// PHP's TOP query matched nobody (an undefined variable) and its OR clauses
// escaped the active and do-not-disturb filters; both are fixed here.
func audienceFilter(audience string, now time.Time) (string, []any) {
	week, twoMonths := now.AddDate(0, 0, -7), now.AddDate(0, 0, -59)
	switch audience {
	case "PLAYING":
		return ` AND (` + lastCharge + ` >= ? OR ps.started_at >= ? OR ` + lastReactivation + ` >= ?)`, []any{week, week, week}
	case "TOP":
		return ` AND ((` + lastCharge + ` >= ? AND ` + lastCharge + ` < ?) OR (ps.started_at >= ? AND ps.started_at < ?) OR (` +
			lastReactivation + ` >= ? AND ` + lastReactivation + ` < ?))`, []any{twoMonths, week, twoMonths, week, twoMonths, week}
	}
	return "", nil
}

// RunOnce processes every schedule due today in Dar es Salaam, returning the
// deliveries it created. Schedules from earlier days stay pending, as in PHP.
func (s Scheduler) RunOnce(ctx context.Context) (int, error) {
	now := time.Now()
	if s.Now != nil {
		now = s.Now()
	}
	local := now.In(darEsSalaam)
	dayStart := time.Date(local.Year(), local.Month(), local.Day(), 0, 0, 0, 0, darEsSalaam).UTC()
	batch := s.Batch
	if batch < 1 {
		batch = 500
	}
	total := 0
	for {
		var scheduleID int64
		err := s.DB.QueryRowContext(ctx, `SELECT qs.id FROM question_schedules qs JOIN products p ON p.id=qs.product_id
			WHERE p.code='soka' AND qs.state IN ('PENDING','PROCESSING') AND qs.content IS NOT NULL
			AND qs.scheduled_for >= ? AND qs.scheduled_for <= ? ORDER BY qs.scheduled_for, qs.id LIMIT 1`, dayStart, now.UTC()).Scan(&scheduleID)
		if errors.Is(err, sql.ErrNoRows) {
			return total, nil
		}
		if err != nil {
			return total, err
		}
		for {
			n, done, err := s.deliver(ctx, scheduleID, now.UTC(), batch)
			total += n
			if err != nil {
				return total, err
			}
			if done {
				break
			}
		}
	}
}

// deliver creates one batch for a schedule while holding the schedule row, so
// two schedulers cannot interleave. It marks the schedule processed when no
// eligible subscription is left, even if there were none at all.
func (s Scheduler) deliver(ctx context.Context, scheduleID int64, now time.Time, batch int) (int, bool, error) {
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return 0, false, err
	}
	defer tx.Rollback()
	var productID int64
	var content, audience, state string
	if err := tx.QueryRowContext(ctx, `SELECT product_id, content, audience, state FROM question_schedules WHERE id=? FOR UPDATE`, scheduleID).
		Scan(&productID, &content, &audience, &state); err != nil {
		return 0, false, err
	}
	if state == "PROCESSED" || state == "CANCELLED" {
		return 0, true, tx.Commit()
	}
	filter, args := audienceFilter(audience, now)
	query := `SELECT ps.id, ps.subscriber_id, s.msisdn, COALESCE(q.question_text, ''),
		(SELECT COALESCE(SUM(pl.delta_points),0) FROM point_ledger pl WHERE pl.product_id=ps.product_id AND pl.subscriber_id=ps.subscriber_id)
		FROM product_subscriptions ps JOIN subscribers s ON s.id=ps.subscriber_id
		LEFT JOIN questions q ON q.id=ps.current_question_id AND q.product_id=ps.product_id
		WHERE ps.product_id=? AND ps.state='ACTIVE' AND ps.do_not_disturb=FALSE
		AND NOT EXISTS (SELECT 1 FROM open_question_deliveries d WHERE d.schedule_id=? AND d.subscription_id=ps.id)` + filter +
		` ORDER BY ps.id LIMIT ?`
	rows, err := tx.QueryContext(ctx, query, append(append([]any{productID, scheduleID}, args...), batch)...)
	if err != nil {
		return 0, false, err
	}
	type target struct {
		subscriptionID, subscriberID, points int64
		msisdn, question                     string
	}
	var targets []target
	for rows.Next() {
		var t target
		if err := rows.Scan(&t.subscriptionID, &t.subscriberID, &t.msisdn, &t.question, &t.points); err != nil {
			rows.Close()
			return 0, false, err
		}
		targets = append(targets, t)
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return 0, false, err
	}
	for _, t := range targets {
		if _, err := tx.ExecContext(ctx, `INSERT INTO open_question_deliveries (schedule_id, subscription_id, product_id, subscriber_id, state)
			VALUES (?, ?, ?, ?, 'QUEUED')`, scheduleID, t.subscriptionID, productID, t.subscriberID); err != nil {
			return 0, false, err
		}
		if _, err := tx.ExecContext(ctx, `INSERT INTO outbound_messages (product_id, subscriber_id, message_kind, body_text, delivery_state, source_event_key)
			VALUES (?, ?, ?, ?, 'PENDING', ?)`, productID, t.subscriberID, sokagame.KindOpenQuestion,
			sokagame.OpenQuestionBody(content, t.question, t.msisdn, t.points),
			"open-question:"+strconv.FormatInt(scheduleID, 10)+":"+strconv.FormatInt(t.subscriptionID, 10)); err != nil {
			return 0, false, err
		}
	}
	done := len(targets) < batch
	if done {
		_, err = tx.ExecContext(ctx, `UPDATE question_schedules SET state='PROCESSED', published_at=UTC_TIMESTAMP(6),
			recipients=(SELECT COUNT(*) FROM open_question_deliveries WHERE schedule_id=?) WHERE id=?`, scheduleID, scheduleID)
	} else {
		_, err = tx.ExecContext(ctx, `UPDATE question_schedules SET state='PROCESSING' WHERE id=?`, scheduleID)
	}
	if err != nil {
		return 0, false, err
	}
	return len(targets), done, tx.Commit()
}
