package sokamo

import (
	"context"
	"fmt"
	"os"
	"strings"
	"testing"
	"time"

	driver "github.com/go-sql-driver/mysql"
	"yas-modernization/internal/callbacks"
	mysqlstore "yas-modernization/internal/store/mysql"
	"yas-modernization/internal/testdb"
	"yas-modernization/internal/workers/sokagame"
	"yas-modernization/internal/workers/sokapoints"
)

func TestPredictAndWinIntegration(t *testing.T) {
	dsn := os.Getenv("TEST_MYSQL_DSN")
	if dsn == "" {
		t.Skip("set TEST_MYSQL_DSN for isolated MySQL integration test")
	}
	cfg, err := driver.ParseDSN(dsn)
	if err != nil || !strings.HasPrefix(cfg.DBName, "yas_test_") {
		t.Fatal("TEST_MYSQL_DSN must select a yas_test_ database")
	}
	store, err := mysqlstore.Open(dsn)
	if err != nil {
		t.Fatal(err)
	}
	defer store.DB.Close()
	ctx := context.Background()
	if err := testdb.Reset(ctx, store.DB); err != nil {
		t.Fatal(err)
	}
	exec := func(q string, args ...any) {
		t.Helper()
		if _, err := store.DB.ExecContext(ctx, q, args...); err != nil {
			t.Fatal(q, err)
		}
	}
	count := func(q string, args ...any) int {
		t.Helper()
		var n int
		if err := store.DB.QueryRowContext(ctx, q, args...).Scan(&n); err != nil {
			t.Fatal(q, err)
		}
		return n
	}
	// 09:00 UTC is 12:00 on 28 October in Dar es Salaam.
	now := time.Date(2026, 10, 28, 9, 0, 0, 0, time.UTC)
	const msisdn = "255700000501"
	exec(`INSERT INTO products(id,code,display_name) VALUES (1,'soka','Soka')`)
	exec(`INSERT INTO subscribers(id,msisdn) VALUES (1,?)`, msisdn)
	exec(`INSERT INTO product_subscriptions(product_id,subscriber_id,state,billing_segment,started_at) VALUES (1,1,'ACTIVE','NORMAL',UTC_TIMESTAMP(6))`)
	exec(`INSERT INTO predict_campaigns(id,product_id,name,status,start_date,end_date) VALUES (1,1,'World Cup','ACTIVE','2026-10-01','2026-11-30')`)
	exec(`INSERT INTO predict_match_days(id,campaign_id,match_date,status) VALUES (1,1,'2026-10-28','PUBLISHED')`)
	exec(`INSERT INTO predict_fixtures(id,match_day_id,slot,display_text,kickoff_at,status) VALUES
		(1,1,1,'A v B',?,'SCHEDULED'),(2,1,2,'C v D',?,'SCHEDULED'),(3,1,3,'E v F',?,'CANCELLED')`,
		now.Add(2*time.Hour), now.Add(-time.Hour), now.Add(3*time.Hour))
	mo := Processor{DB: store.DB, Now: func() time.Time { return now }}
	pay := sokapoints.Processor{DB: store.DB, Now: func() time.Time { return now }}
	seq := 0
	save := func(body string) int64 {
		t.Helper()
		event, err := callbacks.ParseJSON("soka", []byte(body))
		if err != nil {
			t.Fatal(err)
		}
		id, err := store.Save(ctx, event)
		if err != nil {
			t.Fatal(err)
		}
		return id
	}
	send := func(text string) (int64, string, string) {
		t.Helper()
		seq++
		id := save(fmt.Sprintf(`{"requestId":"PRED-%d","requestParam":{"data":[{"name":"Msisdn","value":%q},{"name":"Type","value":"InteractiveResponse"},{"name":"CustomerResponse","value":%q}]}}`, seq, msisdn, text))
		if err := mo.Process(ctx, id); err != nil {
			t.Fatalf("%q: %v", text, err)
		}
		var kind, body string
		err := store.DB.QueryRowContext(ctx, `SELECT message_kind, body_text FROM outbound_messages WHERE interaction_id=(SELECT id FROM interactions WHERE external_request_id=?)`,
			fmt.Sprintf("PRED-%d", seq)).Scan(&kind, &body)
		if err != nil && !strings.Contains(err.Error(), "no rows") {
			t.Fatal(err)
		}
		return id, kind, body
	}
	expect := func(text, wantKind string) string {
		t.Helper()
		_, kind, body := send(text)
		if kind != wantKind {
			t.Fatalf("%q replied %s %q, want %s", text, kind, body, wantKind)
		}
		return body
	}
	payment := func(request, charged, reason, billing string) {
		t.Helper()
		id := save(fmt.Sprintf(`{"requestId":%q,"requestParam":{"data":[{"name":"Msisdn","value":%q},{"name":"Type","value":"Payment"},{"name":"ChargeAmount","value":%q},{"name":"Reason","value":%q},{"name":"BillingId","value":%q}]}}`,
			request, msisdn, charged, reason, billing))
		if err := pay.Process(ctx, id); err != nil {
			t.Fatal(err)
		}
		if err := pay.Process(ctx, id); err != nil {
			t.Fatalf("payment replay: %v", err)
		}
	}
	session := func() string {
		var s string
		if err := store.DB.QueryRowContext(ctx, `SELECT CONCAT(state,'/',COALESCE(selected_slot,'-'),'/',max_slot) FROM predict_sessions WHERE subscriber_id=1`).Scan(&s); err != nil {
			return "none"
		}
		return s
	}

	expect("M2", "PREDICT_HINT")
	if body := expect("predict", "PREDICT_LIST"); body != "Ubashiri wa leo: 1. A v B 2. C v D" {
		t.Fatalf("list %q", body)
	}
	if session() != "LIST_SENT/-/2" {
		t.Fatalf("session %s", session())
	}
	expect("M5", "PREDICT_INVALID_SLOT")
	expect("M2", "PREDICT_AFTER_KICKOFF")
	expect("M1-0", "PREDICT_WRONG_STATE")
	expect("M1 0", "PREDICT_INVALID_SCORE")
	expect("M1", "PREDICT_FIXTURE_CHOSEN")

	// A score records a prediction pending payment and charges 99, with no reply yet.
	scoreID, kind, _ := send("M2-1")
	if kind != "" || session() != "BILLING_PENDING/1/2" {
		t.Fatalf("score reply %q session %s", kind, session())
	}
	request := sokagame.PredictRequestID(scoreID, msisdn)
	if count(`SELECT COUNT(*) FROM payment_attempts pa JOIN predict_submissions s ON s.payment_attempt_id=pa.id
		WHERE pa.external_request_id=? AND pa.payment_kind='PREDICTION' AND pa.amount_minor=99 AND s.status='PENDING_PAYMENT'
		AND s.pred_home_goals=2 AND s.pred_away_goals=1 AND s.fixture_id=1`, request) != 1 {
		t.Fatal("pending prediction and charge")
	}
	// Low balance reopens the fixture without a fallback charge.
	payment(request, "99", "Low balance", "")
	if count(`SELECT COUNT(*) FROM predict_submissions WHERE status='LOW_BALANCE_FAILED'`) != 1 || session() != "FIXTURE_CHOSEN/1/2" ||
		count(`SELECT COUNT(*) FROM outbound_messages WHERE message_kind='PREDICT_LOW_BALANCE'`) != 1 || count(`SELECT COUNT(*) FROM payment_attempts`) != 1 {
		t.Fatal("low-balance prediction outcome")
	}
	scoreID, _, _ = send("m3 : 1")
	payment(sokagame.PredictRequestID(scoreID, msisdn), "99", "", "B-PRED")
	if count(`SELECT COUNT(*) FROM predict_submissions WHERE status='CONFIRMED' AND pred_home_goals=3`) != 1 || session() != "SUBMITTED/-/2" ||
		count(`SELECT COUNT(*) FROM outbound_messages WHERE message_kind='PREDICT_RECEIVED'`) != 1 || count(`SELECT COUNT(*) FROM point_ledger`) != 0 {
		t.Fatal("confirmed prediction outcome")
	}
	// The confirmed fixture is blocked and dropped from the next list.
	expect("M1", "PREDICT_ALREADY")
	if body := expect("PREDICT", "PREDICT_LIST"); body != "Ubashiri wa leo: 2. C v D" {
		t.Fatalf("second list %q", body)
	}
	// An expired session is removed.
	exec(`UPDATE predict_sessions SET expires_at=?`, now.Add(-time.Minute))
	expect("M2", "PREDICT_EXPIRED")
	if session() != "none" {
		t.Fatal("expired session must be deleted")
	}
	// Inactive subscribers and days without a published match day.
	exec(`UPDATE predict_match_days SET status='DRAFT'`)
	expect("PREDICT", "PREDICT_NO_FIXTURES")
	exec(`UPDATE product_subscriptions SET state='INACTIVE'`)
	expect("PREDICT", "PREDICT_INACTIVE")
	if count(`SELECT COUNT(*) FROM charge_intents`) != 2 || count(`SELECT COUNT(*) FROM answers`) != 0 {
		t.Fatal("prediction traffic must never become an answer charge")
	}
}
