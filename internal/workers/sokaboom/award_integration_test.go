package sokaboom

import (
	"context"
	"os"
	"strings"
	"testing"

	driver "github.com/go-sql-driver/mysql"
	mysqlstore "yas-modernization/internal/store/mysql"
	"yas-modernization/internal/testdb"
)

func TestAwardIntegration(t *testing.T) {
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
	exec(`INSERT INTO products(id,code,display_name) VALUES (1,'soka','Soka')`)
	for i, c := range []struct{ state, segment string }{{"ACTIVE", "BOOM"}, {"INACTIVE", "BOOM"}, {"ACTIVE", "NORMAL"}, {"ACTIVE", "BOOM"}, {"ACTIVE", "BOOM"}} {
		exec(`INSERT INTO subscribers(id,msisdn) VALUES (?,?)`, i+1, "25570000030"+string(rune('0'+i)))
		exec(`INSERT INTO product_subscriptions(id,product_id,subscriber_id,state,billing_segment,started_at) VALUES (?,1,?,?,?,UTC_TIMESTAMP(6))`, i+1, i+1, c.state, c.segment)
	}
	if _, err := Award(ctx, store.DB, "bad round!", 2); err == nil {
		t.Fatal("invalid round accepted")
	}
	// A batch of 2 forces several transactions.
	n, err := Award(ctx, store.DB, "2026-10-28", 2)
	if err != nil || n != 4 {
		t.Fatalf("awarded %d err=%v", n, err)
	}
	if count(`SELECT COUNT(*) FROM product_subscriptions WHERE boom_credits=1`) != 4 || count(`SELECT COUNT(*) FROM product_subscriptions WHERE billing_segment='NORMAL' AND boom_credits=0`) != 1 {
		t.Fatal("only BOOM subscriptions, inactive included, get the credit")
	}
	if count(`SELECT COUNT(*) FROM outbound_messages WHERE message_kind='BOOM_CREDIT_NOTIFY'`) != 4 {
		t.Fatal("one notification per award")
	}
	// The customer spends the credit; rerunning the round must not grant it again.
	exec(`UPDATE product_subscriptions SET boom_credits=0 WHERE id=1`)
	if n, err := Award(ctx, store.DB, "2026-10-28", 2); err != nil || n != 0 {
		t.Fatalf("rerun awarded %d err=%v", n, err)
	}
	if count(`SELECT boom_credits FROM product_subscriptions WHERE id=1`) != 0 || count(`SELECT COUNT(*) FROM outbound_messages`) != 4 {
		t.Fatal("rerun re-granted a spent credit or re-notified")
	}
	// A new round grants again, setting the credit to 1 rather than adding.
	exec(`UPDATE product_subscriptions SET boom_credits=1 WHERE id=4`)
	if n, err := Award(ctx, store.DB, "2026-11-04", 500); err != nil || n != 4 {
		t.Fatalf("next round awarded %d err=%v", n, err)
	}
	if count(`SELECT COUNT(*) FROM product_subscriptions WHERE boom_credits>1`) != 0 || count(`SELECT boom_credits FROM product_subscriptions WHERE id=1`) != 1 {
		t.Fatal("next round must set the credit to 1")
	}
}
