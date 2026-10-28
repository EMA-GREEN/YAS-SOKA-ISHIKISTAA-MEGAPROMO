package sokamo

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"strconv"
	"strings"
	"time"

	"yas-modernization/internal/workers/sokagame"
)

// Predictions in these states stop another prediction for the same fixture.
const blockingPredictions = `'PENDING_PAYMENT','CONFIRMED','WON','LOST'`

type predictSession struct {
	campaignID, matchDayID, maxSlot int64
	state                           string
	selectedSlot, selectedFixture   sql.NullInt64
	expiresAt                       time.Time
}

type fixture struct {
	id, slot int64
	display  string
	kickoff  time.Time
}

// predict follows PredictAndWin::handleMo: PREDICT lists today's fixtures,
// M{slot} chooses one, and M{home}-{away} records a prediction and charges for it.
// Every step answers with an SMS, as PHP does.
func (p Processor) predict(ctx context.Context, m *mo, sub *subscription) error {
	text := strings.TrimSpace(m.fields["CustomerResponse"])
	if err := m.recordInteraction(ctx, "MO", text); err != nil {
		return err
	}
	reply := func(kind, body string) error { return m.message(ctx, kind, body) }
	active := sub != nil && sub.state == "ACTIVE"
	step, a, b := sokagame.ParsePredict(text)
	if step == sokagame.PredictEntry {
		if !active {
			return reply("PREDICT_INACTIVE", sokagame.PredictInactive)
		}
		return p.predictList(ctx, m)
	}
	session, err := m.loadPredictSession(ctx)
	if err != nil {
		return err
	}
	if session == nil {
		return reply("PREDICT_HINT", sokagame.PredictFirst)
	}
	if session.expiresAt.Before(m.now) {
		if _, err := m.tx.ExecContext(ctx, `DELETE FROM predict_sessions WHERE product_id=? AND subscriber_id=?`, m.productID, m.subscriberID); err != nil {
			return err
		}
		return reply("PREDICT_EXPIRED", sokagame.PredictExpired)
	}
	switch step {
	case sokagame.PredictScore:
		if session.state != "FIXTURE_CHOSEN" {
			return reply("PREDICT_WRONG_STATE", sokagame.PredictWrongState)
		}
		if !active {
			return reply("PREDICT_INACTIVE", sokagame.PredictInactive)
		}
		f, err := m.fixtureByID(ctx, session.selectedFixture.Int64)
		if err != nil || f == nil {
			if err == nil {
				err = reply("PREDICT_WRONG_STATE", sokagame.PredictWrongState)
			}
			return err
		}
		if !f.kickoff.After(m.now) {
			return reply("PREDICT_AFTER_KICKOFF", sokagame.PredictAfterKickoff)
		}
		if blocked, err := m.predictionBlocked(ctx, f.id); err != nil || blocked {
			if err == nil {
				err = reply("PREDICT_ALREADY", sokagame.PredictAlready)
			}
			return err
		}
		if sub.segment == "UNVERIFIED" {
			return fmt.Errorf("%w: billing segment unverified", ErrNeedsReview)
		}
		return m.submitPrediction(ctx, *sub, session, f, a, b, text)
	case sokagame.PredictSlot:
		if session.state != "LIST_SENT" && session.state != "SUBMITTED" {
			return reply("PREDICT_WRONG_STATE", sokagame.PredictWrongState)
		}
		if a < 1 || int64(a) > session.maxSlot {
			return reply("PREDICT_INVALID_SLOT", sokagame.PredictInvalidSlot)
		}
		f, err := m.fixtureBySlot(ctx, session.matchDayID, int64(a))
		if err != nil || f == nil {
			if err == nil {
				err = reply("PREDICT_INVALID_SLOT", sokagame.PredictInvalidSlot)
			}
			return err
		}
		if blocked, err := m.predictionBlocked(ctx, f.id); err != nil || blocked {
			if err == nil {
				err = reply("PREDICT_ALREADY", sokagame.PredictAlready)
			}
			return err
		}
		if !f.kickoff.After(m.now) {
			return reply("PREDICT_AFTER_KICKOFF", sokagame.PredictAfterKickoff)
		}
		if _, err := m.tx.ExecContext(ctx, `UPDATE predict_sessions SET state='FIXTURE_CHOSEN', selected_slot=?, selected_fixture_id=?,
			pending_submission_id=NULL, expires_at=? WHERE product_id=? AND subscriber_id=?`,
			f.slot, f.id, m.now.Add(24*time.Hour).UTC(), m.productID, m.subscriberID); err != nil {
			return err
		}
		return reply("PREDICT_FIXTURE_CHOSEN", sokagame.PredictFixtureChosen)
	case sokagame.PredictBadScore:
		return reply("PREDICT_INVALID_SCORE", sokagame.PredictInvalidScore)
	}
	return reply("PREDICT_WRONG_STATE", sokagame.PredictWrongState)
}

// predictList sends today's published fixtures the subscriber has not yet
// predicted and starts a 24-hour session.
func (p Processor) predictList(ctx context.Context, m *mo) error {
	today := sokagame.DarDate(m.now)
	var campaignID, matchDayID int64
	var listText sql.NullString
	err := m.tx.QueryRowContext(ctx, `SELECT c.id, d.id, d.list_sms_text FROM predict_campaigns c
		JOIN predict_match_days d ON d.campaign_id=c.id AND d.match_date=? AND d.status='PUBLISHED'
		WHERE c.product_id=? AND c.status='ACTIVE' AND c.start_date<=? AND c.end_date>=?
		ORDER BY c.id DESC LIMIT 1`, today, m.productID, today, today).Scan(&campaignID, &matchDayID, &listText)
	if errors.Is(err, sql.ErrNoRows) {
		return m.message(ctx, "PREDICT_NO_FIXTURES", sokagame.PredictNoFixtures)
	}
	if err != nil {
		return err
	}
	rows, err := m.tx.QueryContext(ctx, `SELECT f.id, f.slot, f.display_text, f.kickoff_at,
		EXISTS (SELECT 1 FROM predict_submissions s WHERE s.subscriber_id=? AND s.fixture_id=f.id AND s.status IN (`+blockingPredictions+`))
		FROM predict_fixtures f WHERE f.match_day_id=? AND f.status='SCHEDULED' ORDER BY f.slot`, m.subscriberID, matchDayID)
	if err != nil {
		return err
	}
	var all int64
	var available []string
	for rows.Next() {
		var f fixture
		var blocked bool
		if err := rows.Scan(&f.id, &f.slot, &f.display, &f.kickoff, &blocked); err != nil {
			rows.Close()
			return err
		}
		all++
		if !blocked {
			available = append(available, strconv.FormatInt(f.slot, 10)+". "+f.display)
		}
	}
	rows.Close()
	if err := rows.Err(); err != nil {
		return err
	}
	if all == 0 {
		return m.message(ctx, "PREDICT_NO_FIXTURES", sokagame.PredictNoFixtures)
	}
	if len(available) == 0 {
		return m.message(ctx, "PREDICT_ALL_DONE", sokagame.PredictAllDone)
	}
	// PHP counts every scheduled fixture for the highest slot, but lists only
	// those still open to this subscriber.
	if _, err := m.tx.ExecContext(ctx, `INSERT INTO predict_sessions
		(product_id, subscriber_id, campaign_id, match_day_id, max_slot, state, expires_at) VALUES (?, ?, ?, ?, ?, 'LIST_SENT', ?)
		ON DUPLICATE KEY UPDATE campaign_id=VALUES(campaign_id), match_day_id=VALUES(match_day_id), max_slot=VALUES(max_slot),
		state='LIST_SENT', selected_slot=NULL, selected_fixture_id=NULL, pending_submission_id=NULL, expires_at=VALUES(expires_at)`,
		m.productID, m.subscriberID, campaignID, matchDayID, all, m.now.Add(24*time.Hour).UTC()); err != nil {
		return err
	}
	body := sokagame.PredictListHeader + " " + strings.Join(available, " ")
	if strings.TrimSpace(listText.String) != "" {
		body = listText.String
	}
	return m.message(ctx, "PREDICT_LIST", body)
}

// submitPrediction records the prediction pending payment and its charge. The
// payment callback confirms it or reopens the chosen fixture.
func (m *mo) submitPrediction(ctx context.Context, sub subscription, s *predictSession, f *fixture, home, away int, text string) error {
	if len(text) > 32 {
		text = text[:32]
	}
	res, err := m.tx.ExecContext(ctx, `INSERT INTO predict_submissions
		(product_id, subscriber_id, campaign_id, match_day_id, fixture_id, slot, pred_home_goals, pred_away_goals, raw_text, interaction_id, status)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING_PAYMENT')`, sub.productID, sub.subscriberID, s.campaignID, s.matchDayID, f.id, f.slot, home, away, text, m.interactionID)
	if err != nil {
		return err
	}
	submissionID, err := res.LastInsertId()
	if err != nil {
		return err
	}
	if _, err := m.tx.ExecContext(ctx, `UPDATE predict_sessions SET state='BILLING_PENDING', pending_submission_id=?, expires_at=?
		WHERE product_id=? AND subscriber_id=?`, submissionID, m.now.Add(24*time.Hour).UTC(), m.productID, m.subscriberID); err != nil {
		return err
	}
	attemptID, err := m.charge(ctx, sub, sokagame.PredictRequestID(m.callbackID, m.msisdn), "PREDICTION", sokagame.PredictCharge, m.offer(sub.segment))
	if err != nil {
		return err
	}
	_, err = m.tx.ExecContext(ctx, `UPDATE predict_submissions SET payment_attempt_id=? WHERE id=?`, attemptID, submissionID)
	return err
}

func (m *mo) loadPredictSession(ctx context.Context) (*predictSession, error) {
	if err := m.ensureSubscriber(ctx); err != nil {
		return nil, err
	}
	var s predictSession
	err := m.tx.QueryRowContext(ctx, `SELECT campaign_id, match_day_id, max_slot, state, selected_slot, selected_fixture_id, expires_at
		FROM predict_sessions WHERE product_id=? AND subscriber_id=? FOR UPDATE`, m.productID, m.subscriberID).
		Scan(&s.campaignID, &s.matchDayID, &s.maxSlot, &s.state, &s.selectedSlot, &s.selectedFixture, &s.expiresAt)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	return &s, err
}

func (m *mo) fixtureByID(ctx context.Context, id int64) (*fixture, error) {
	return m.scanFixture(m.tx.QueryRowContext(ctx, `SELECT id, slot, display_text, kickoff_at FROM predict_fixtures WHERE id=?`, id))
}

func (m *mo) fixtureBySlot(ctx context.Context, matchDayID, slot int64) (*fixture, error) {
	return m.scanFixture(m.tx.QueryRowContext(ctx, `SELECT id, slot, display_text, kickoff_at FROM predict_fixtures WHERE match_day_id=? AND slot=?`, matchDayID, slot))
}

func (m *mo) scanFixture(row *sql.Row) (*fixture, error) {
	var f fixture
	err := row.Scan(&f.id, &f.slot, &f.display, &f.kickoff)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	return &f, err
}

func (m *mo) predictionBlocked(ctx context.Context, fixtureID int64) (bool, error) {
	var n int
	err := m.tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM predict_submissions WHERE subscriber_id=? AND fixture_id=? AND status IN (`+blockingPredictions+`)`,
		m.subscriberID, fixtureID).Scan(&n)
	return n > 0, err
}
