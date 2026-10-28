package sokaopen

import (
	"context"
	"os"
	"strings"
	"testing"
	"time"

	driver "github.com/go-sql-driver/mysql"
	mysqlstore "yas-modernization/internal/store/mysql"
	"yas-modernization/internal/testdb"
)

func TestSchedulerIntegration(t *testing.T) {
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
	// 09:00 UTC is 12:00 in Dar es Salaam.
	now := time.Date(2026, 10, 28, 9, 0, 0, 0, time.UTC)
	days := func(n int) time.Time { return now.AddDate(0, 0, -n) }
	exec(`INSERT INTO products(id,code,display_name) VALUES (1,'soka','Soka')`)
	exec(`INSERT INTO questions(id,product_id,question_text,answer_key,points) VALUES (41,1,'Q-OPEN','A',100)`)
	// id: state, do-not-disturb, started, last successful charge
	subs := []struct {
		state   string
		dnd     bool
		started time.Time
		charged *time.Time
	}{
		{"ACTIVE", false, days(200), ptr(days(2))},  // 1 playing via charge
		{"ACTIVE", false, days(3), nil},             // 2 playing via start
		{"ACTIVE", false, days(30), nil},            // 3 top
		{"ACTIVE", false, days(200), ptr(days(20))}, // 4 top via charge
		{"ACTIVE", false, days(100), nil},           // 5 lapsed: all only
		{"ACTIVE", true, days(2), nil},              // 6 do-not-disturb
		{"INACTIVE", false, days(2), nil},           // 7 inactive
	}
	for i, s := range subs {
		id := i + 1
		exec(`INSERT INTO subscribers(id,msisdn) VALUES (?,?)`, id, "25570000040"+string(rune('0'+id)))
		exec(`INSERT INTO product_subscriptions(id,product_id,subscriber_id,state,billing_segment,started_at,current_question_id,do_not_disturb)
			VALUES (?,1,?,?,'NORMAL',?,41,?)`, id, id, s.state, s.started, s.dnd)
		if s.charged != nil {
			exec(`INSERT INTO payment_attempts(product_id,subscriber_id,external_request_id,payment_kind,channel,amount_minor,currency,state,requested_at,settled_at)
				VALUES (1,?,?,'ANSWER_CHARGE','SMS',99,'TZS','SUCCESS',?,?)`, id, "OPEN-PAY-"+string(rune('0'+id)), *s.charged, *s.charged)
		}
	}
	exec(`INSERT INTO point_ledger(product_id,subscriber_id,delta_points,reason,source_event_key,occurred_at) VALUES (1,1,75,'TEST','open-seed',UTC_TIMESTAMP(6))`)
	schedule := func(id int, audience string, at time.Time) {
		exec(`INSERT INTO question_schedules(id,product_id,scheduled_for,state,content,audience) VALUES (?,1,?,'PENDING',?,?)`,
			id, at, "Alama %s kwa %msisdn;", audience)
	}
	schedule(1, "ALL", now.Add(-time.Hour))
	schedule(2, "PLAYING", now.Add(-time.Minute))
	schedule(3, "TOP", now.Add(-time.Minute))
	schedule(4, "ALL", now.Add(-24*time.Hour)) // yesterday: never sent, as in PHP
	schedule(5, "ALL", now.Add(time.Hour))     // later today: not yet due
	s := Scheduler{DB: store.DB, Now: func() time.Time { return now }, Batch: 2}
	n, err := s.RunOnce(ctx)
	if err != nil {
		t.Fatal(err)
	}
	want := map[int][]int{1: {1, 2, 3, 4, 5}, 2: {1, 2}, 3: {3, 4}}
	if n != 9 {
		t.Fatalf("created %d deliveries, want 9", n)
	}
	for id, subs := range want {
		for _, sub := range subs {
			if count(`SELECT COUNT(*) FROM open_question_deliveries WHERE schedule_id=? AND subscription_id=?`, id, sub) != 1 {
				t.Errorf("schedule %d missing subscription %d", id, sub)
			}
		}
		if count(`SELECT COUNT(*) FROM open_question_deliveries WHERE schedule_id=?`, id) != len(subs) ||
			count(`SELECT recipients FROM question_schedules WHERE id=? AND state='PROCESSED'`, id) != len(subs) {
			t.Errorf("schedule %d recipients", id)
		}
	}
	if count(`SELECT COUNT(*) FROM question_schedules WHERE id IN (4,5) AND state='PENDING'`) != 2 {
		t.Fatal("yesterday's and future schedules must stay pending")
	}
	var body string
	if err := store.DB.QueryRowContext(ctx, `SELECT body_text FROM outbound_messages WHERE source_event_key='open-question:1:1'`).Scan(&body); err != nil ||
		body != "Alama 75 kwa 255700000401; Q-OPEN" {
		t.Fatalf("body %q err=%v", body, err)
	}
	// Rerunning finds nothing new; a schedule with no audience is still closed.
	if n, err := s.RunOnce(ctx); err != nil || n != 0 {
		t.Fatalf("rerun created %d err=%v", n, err)
	}
	exec(`UPDATE product_subscriptions SET do_not_disturb=TRUE`)
	schedule(6, "ALL", now.Add(-time.Second))
	if n, err := s.RunOnce(ctx); err != nil || n != 0 || count(`SELECT COUNT(*) FROM question_schedules WHERE id=6 AND state='PROCESSED' AND recipients=0`) != 1 {
		t.Fatalf("empty schedule n=%d err=%v", n, err)
	}
}

func ptr(t time.Time) *time.Time { return &t }
