package sokapoints

import (
	"context"
	"errors"
	"fmt"
	"os"
	"strings"
	"testing"

	driver "github.com/go-sql-driver/mysql"
	"yas-modernization/internal/callbacks"
	mysqlstore "yas-modernization/internal/store/mysql"
	"yas-modernization/internal/testdb"
)

func TestProcessIntegration(t *testing.T) {
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
	var connected string
	if err := store.DB.QueryRowContext(ctx, "SELECT DATABASE()").Scan(&connected); err != nil || connected != cfg.DBName {
		t.Fatal("connected database differs from TEST_MYSQL_DSN")
	}
	if err := testdb.Reset(ctx, store.DB); err != nil {
		t.Fatal(err)
	}
	if _, err := store.DB.ExecContext(ctx, `INSERT INTO products(id,code,display_name) VALUES (1,'soka','Soka')`); err != nil {
		t.Fatal(err)
	}
	if _, err := store.DB.ExecContext(ctx, `INSERT INTO subscribers(id,msisdn) VALUES (1,'255700000001')`); err != nil {
		t.Fatal(err)
	}
	if _, err := store.DB.ExecContext(ctx, `INSERT INTO point_ledger(product_id,subscriber_id,delta_points,reason,source_event_key,occurred_at)
		VALUES (1,1,10,'TEST','existing-points',UTC_TIMESTAMP(6))`); err != nil {
		t.Fatal(err)
	}
	processor := Processor{DB: store.DB}
	seed := func(request string, amount int64, charged, reason, billing string) (int64, int64) {
		t.Helper()
		res, err := store.DB.ExecContext(ctx, `INSERT INTO payment_attempts
			(product_id,subscriber_id,external_request_id,payment_kind,channel,amount_minor,currency,state,requested_at)
			VALUES (1,1,?,'POINT_PURCHASE','SMS',?,'TZS','PENDING',UTC_TIMESTAMP(6))`, request, amount)
		if err != nil {
			t.Fatal(err)
		}
		attemptID, _ := res.LastInsertId()
		body := fmt.Sprintf(`{"requestId":%q,"requestParam":{"data":[{"name":"Msisdn","value":"255700000001"},{"name":"Type","value":"Payment"},{"name":"ChargeAmount","value":%q},{"name":"Reason","value":%q},{"name":"BillingId","value":%q}]}}`, request, charged, reason, billing)
		event, err := callbacks.ParseJSON("soka", []byte(body))
		if err != nil {
			t.Fatal(err)
		}
		callbackID, err := store.Save(ctx, event)
		if err != nil {
			t.Fatal(err)
		}
		return attemptID, callbackID
	}
	checkState := func(attemptID int64, want string) {
		t.Helper()
		var got string
		if err := store.DB.QueryRowContext(ctx, `SELECT state FROM payment_attempts WHERE id=?`, attemptID).Scan(&got); err != nil || got != want {
			t.Fatalf("attempt %d state=%q err=%v; want %q", attemptID, got, err, want)
		}
	}
	successID, successCallback := seed("PU-SUCCESS", 300, "300", "", "B-1")
	if err := processor.Process(ctx, successCallback); err != nil {
		t.Fatal(err)
	}
	if err := processor.Process(ctx, successCallback); err != nil {
		t.Fatalf("replay: %v", err)
	}
	checkState(successID, "SUCCESS")
	var points, balance, ledgerRows, messageRows int64
	if err := store.DB.QueryRowContext(ctx, `SELECT COALESCE(SUM(delta_points),0), COUNT(*) FROM point_ledger WHERE payment_attempt_id=?`, successID).Scan(&points, &ledgerRows); err != nil {
		t.Fatal(err)
	}
	if err := store.DB.QueryRowContext(ctx, `SELECT COALESCE(SUM(delta_points),0) FROM point_ledger WHERE product_id=1 AND subscriber_id=1`).Scan(&balance); err != nil {
		t.Fatal(err)
	}
	var message string
	if err := store.DB.QueryRowContext(ctx, `SELECT body_text FROM outbound_messages WHERE source_event_key=?`, sourceKey(successID)).Scan(&message); err != nil {
		t.Fatal(err)
	}
	if err := store.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM outbound_messages WHERE source_event_key=?`, sourceKey(successID)).Scan(&messageRows); err != nil {
		t.Fatal(err)
	}
	if points != 300 || ledgerRows != 1 || balance != 310 || messageRows != 1 || !strings.Contains(message, "310") {
		t.Fatalf("success points=%d ledger=%d balance=%d messages=%d text=%q", points, ledgerRows, balance, messageRows, message)
	}
	lowID, lowCallback := seed("PU-LOW", 500, "null", "low balance", "")
	if err := processor.Process(ctx, lowCallback); err != nil {
		t.Fatal(err)
	}
	checkState(lowID, "LOW_BALANCE_FAILED")
	if err := store.DB.QueryRowContext(ctx, `SELECT body_text FROM outbound_messages WHERE source_event_key=?`, sourceKey(lowID)).Scan(&message); err != nil || message != lowBalanceMessage {
		t.Fatalf("low-balance message=%q err=%v", message, err)
	}
	failedID, failedCallback := seed("PU-ERROR", 1000, "", "gateway timeout", "")
	if err := processor.Process(ctx, failedCallback); err != nil {
		t.Fatal(err)
	}
	checkState(failedID, "BILLING_ERROR")
	notPointID, notPointCallback := seed("PLAY-NORMAL", 300, "300", "", "B-X")
	if err := processor.Process(ctx, notPointCallback); !errors.Is(err, ErrNotApplicable) {
		t.Fatalf("ordinary play callback err=%v", err)
	}
	checkState(notPointID, "PENDING")
	unknownID, unknownCallback := seed("PU-UNKNOWN-TIER", 750, "750", "", "B-Y")
	if err := processor.Process(ctx, unknownCallback); !errors.Is(err, ErrInvalid) {
		t.Fatalf("unknown tier err=%v", err)
	}
	checkState(unknownID, "PENDING")
	missingBillingID, missingBillingCallback := seed("PU-NO-BILLING-ID", 500, "500", "", "")
	if err := processor.Process(ctx, missingBillingCallback); !errors.Is(err, ErrInvalid) {
		t.Fatalf("missing billing ID err=%v", err)
	}
	checkState(missingBillingID, "PENDING")
	badID, badCallback := seed("PU-MISMATCH", 2000, "5000", "", "B-2")
	if err := processor.Process(ctx, badCallback); !errors.Is(err, ErrInvalid) {
		t.Fatalf("amount mismatch err=%v", err)
	}
	checkState(badID, "PENDING")
	rollbackID, rollbackCallback := seed("PU-ROLLBACK", 5000, "5000", "", "B-3")
	if _, err := store.DB.ExecContext(ctx, `CREATE TRIGGER fail_test_message BEFORE INSERT ON outbound_messages FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected message failure'`); err != nil {
		t.Fatal(err)
	}
	defer func() {
		if _, err := store.DB.ExecContext(ctx, `DROP TRIGGER fail_test_message`); err != nil {
			t.Errorf("drop test trigger: %v", err)
		}
	}()
	if err := processor.Process(ctx, rollbackCallback); err == nil {
		t.Fatal("expected injected message failure")
	}
	checkState(rollbackID, "PENDING")
	var awards, markers int64
	if err := store.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM point_ledger WHERE payment_attempt_id=?`, rollbackID).Scan(&awards); err != nil {
		t.Fatal(err)
	}
	if err := store.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM processed_events WHERE callback_event_id=?`, rollbackCallback).Scan(&markers); err != nil {
		t.Fatal(err)
	}
	if awards != 0 || markers != 0 {
		t.Fatalf("rollback left awards=%d markers=%d", awards, markers)
	}
}
