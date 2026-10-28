package sokamo

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
	"yas-modernization/internal/workers/sokapoints"
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
	if _, err := store.DB.ExecContext(ctx, `INSERT INTO product_subscriptions(product_id,subscriber_id,state,billing_segment,started_at)
		VALUES (1,1,'ACTIVE','NORMAL',UTC_TIMESTAMP(6))`); err != nil {
		t.Fatal(err)
	}
	callback := func(request, keyword string) int64 {
		t.Helper()
		body := fmt.Sprintf(`{"requestId":%q,"requestParam":{"data":[{"name":"Msisdn","value":"255700000001"},{"name":"Type","value":"InteractiveResponse"},{"name":"CustomerResponse","value":%q},{"name":"TransactionId","value":"T-1"}]}}`, request, keyword)
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
	processor := Processor{DB: store.DB}
	first := callback("MO-1", " p300 ")
	if err := processor.Process(ctx, first); err != nil {
		t.Fatal(err)
	}
	if err := processor.Process(ctx, first); err != nil {
		t.Fatalf("replay: %v", err)
	}
	var attemptID, amount int64
	var requestID, offer, state string
	if err := store.DB.QueryRowContext(ctx, `SELECT pa.id, pa.external_request_id, pa.amount_minor, pa.state, ci.offer_code
		FROM payment_attempts pa JOIN charge_intents ci ON ci.payment_attempt_id=pa.id WHERE ci.source_callback_event_id=?`, first).
		Scan(&attemptID, &requestID, &amount, &state, &offer); err != nil {
		t.Fatal(err)
	}
	if attemptID == 0 || !strings.HasPrefix(requestID, "PU") || amount != 300 || state != "PENDING" || offer != "017008600005" {
		t.Fatalf("attempt id=%d request=%q amount=%d state=%q offer=%q", attemptID, requestID, amount, state, offer)
	}
	// Complete the database-side MO -> pending charge -> provider callback -> points path.
	paymentBody := fmt.Sprintf(`{"requestId":%q,"requestParam":{"data":[{"name":"Msisdn","value":"255700000001"},{"name":"Type","value":"Payment"},{"name":"ChargeAmount","value":"300"},{"name":"BillingId","value":"B-1"}]}}`, requestID)
	paymentEvent, err := callbacks.ParseJSON("soka", []byte(paymentBody))
	if err != nil {
		t.Fatal(err)
	}
	paymentCallbackID, err := store.Save(ctx, paymentEvent)
	if err != nil {
		t.Fatal(err)
	}
	paymentWorker := sokapoints.Processor{DB: store.DB}
	if err := paymentWorker.Process(ctx, paymentCallbackID); err != nil {
		t.Fatal(err)
	}
	if err := paymentWorker.Process(ctx, paymentCallbackID); err != nil {
		t.Fatalf("payment replay: %v", err)
	}
	var awarded int64
	if err := store.DB.QueryRowContext(ctx, `SELECT COALESCE(SUM(delta_points),0) FROM point_ledger WHERE payment_attempt_id=?`, attemptID).Scan(&awarded); err != nil || awarded != 300 {
		t.Fatalf("MO-to-payment award=%d err=%v", awarded, err)
	}
	var count int64
	if err := store.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM payment_attempts`).Scan(&count); err != nil || count != 1 {
		t.Fatalf("replay attempts=%d err=%v", count, err)
	}
	unknown := callback("MO-2", "P750")
	if err := processor.Process(ctx, unknown); err != nil {
		t.Fatal(err)
	}
	var menu string
	if err := store.DB.QueryRowContext(ctx, `SELECT body_text FROM outbound_messages WHERE source_event_key=?`, sourceKey(unknown)).Scan(&menu); err != nil || menu != purchaseMenu {
		t.Fatalf("unknown-keyword menu=%q err=%v", menu, err)
	}
	if err := store.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM charge_intents`).Scan(&count); err != nil || count != 1 {
		t.Fatalf("unknown keyword charged=%d err=%v", count, err)
	}
	// Unrecognized text is a charged answer in PHP; without a current question it needs review.
	other := callback("MO-3", "HELLO")
	if err := processor.Process(ctx, other); !errors.Is(err, ErrNeedsReview) {
		t.Fatalf("answer without question err=%v", err)
	}
	if _, err := store.DB.ExecContext(ctx, `UPDATE product_subscriptions SET billing_segment='UNVERIFIED'`); err != nil {
		t.Fatal(err)
	}
	unverified := callback("MO-4", "P600")
	if err := processor.Process(ctx, unverified); !errors.Is(err, ErrNeedsReview) {
		t.Fatalf("unverified billing segment err=%v", err)
	}
	if err := store.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM charge_intents`).Scan(&count); err != nil || count != 1 {
		t.Fatalf("unverified segment charged=%d err=%v", count, err)
	}
	if _, err := store.DB.ExecContext(ctx, `UPDATE product_subscriptions SET billing_segment='BOOM'`); err != nil {
		t.Fatal(err)
	}
	boom := callback("MO-5", "P10000")
	if err := processor.Process(ctx, boom); err != nil {
		t.Fatal(err)
	}
	if err := store.DB.QueryRowContext(ctx, `SELECT offer_code FROM charge_intents WHERE source_callback_event_id=?`, boom).Scan(&offer); err != nil || offer != "017008600272" {
		t.Fatalf("BOOM offer=%q err=%v", offer, err)
	}
	rollback := callback("MO-6", "P600")
	if _, err := store.DB.ExecContext(ctx, `CREATE TRIGGER fail_test_charge BEFORE INSERT ON charge_intents FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected charge failure'`); err != nil {
		t.Fatal(err)
	}
	defer func() {
		if _, err := store.DB.ExecContext(ctx, `DROP TRIGGER fail_test_charge`); err != nil {
			t.Errorf("drop test trigger: %v", err)
		}
	}()
	if err := processor.Process(ctx, rollback); err == nil {
		t.Fatal("expected injected charge failure")
	}
	for _, query := range []string{
		`SELECT COUNT(*) FROM interactions WHERE external_request_id='MO-6'`,
		`SELECT COUNT(*) FROM processed_events WHERE callback_event_id=(SELECT id FROM callback_events WHERE external_request_id='MO-6')`,
	} {
		if err := store.DB.QueryRowContext(ctx, query).Scan(&count); err != nil || count != 0 {
			t.Fatalf("rollback query=%q count=%d err=%v", query, count, err)
		}
	}
}
