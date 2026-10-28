package relay_test

import (
	"context"
	"database/sql"
	"io"
	"log/slog"
	"net/http/httptest"
	"os"
	"strings"
	"testing"
	"time"

	driver "github.com/go-sql-driver/mysql"
	amqp "github.com/rabbitmq/amqp091-go"
	"yas-modernization/internal/callbacks"
	"yas-modernization/internal/messaging"
	"yas-modernization/internal/obs"
	"yas-modernization/internal/relay"
	mysqlstore "yas-modernization/internal/store/mysql"
	"yas-modernization/internal/workers/sokapoints"
)

func TestRelayToPointWorkerIntegration(t *testing.T) {
	dsn, amqpURL := os.Getenv("TEST_MYSQL_DSN"), os.Getenv("TEST_AMQP_URL")
	if dsn == "" || amqpURL == "" {
		t.Skip("set TEST_MYSQL_DSN and TEST_AMQP_URL for isolated integration test")
	}
	cfg, err := driver.ParseDSN(dsn)
	if err != nil || !strings.HasPrefix(cfg.DBName, "yas_test_") {
		t.Fatal("TEST_MYSQL_DSN must use a yas_test_ database")
	}
	store, err := mysqlstore.Open(dsn)
	if err != nil {
		t.Fatal(err)
	}
	defer store.DB.Close()
	ctx, cancel := context.WithTimeout(context.Background(), 45*time.Second)
	defer cancel()
	var dbName string
	if err := store.DB.QueryRowContext(ctx, "SELECT DATABASE()").Scan(&dbName); err != nil || dbName != cfg.DBName {
		t.Fatal("database identity mismatch", err)
	}
	conn, err := amqp.Dial(amqpURL)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	ch, err := conn.Channel()
	if err != nil {
		t.Fatal(err)
	}
	defer ch.Close()
	logger := slog.New(slog.NewTextHandler(io.Discard, nil))
	if _, err := store.DB.ExecContext(ctx, `DROP TRIGGER IF EXISTS fail_e2e_message`); err != nil {
		t.Fatal(err)
	}
	for _, table := range []string{"processed_events", "outbound_message_events", "outbound_messages", "point_ledger", "payment_events", "charge_intents", "payment_attempts", "interactions", "product_subscriptions", "callback_outbox", "callback_events", "products", "subscribers"} {
		if _, err := store.DB.ExecContext(ctx, "DELETE FROM "+table); err != nil {
			t.Fatal(table, err)
		}
	}
	if err := messaging.DeclareSokaPointPurchases(ch); err != nil {
		t.Fatal(err)
	}
	for _, queue := range []string{"yas.soka.point-purchases", "yas.soka.point-purchases.dead"} {
		if _, err := ch.QueuePurge(queue, false); err != nil {
			t.Fatal(queue, err)
		}
	}
	if _, err := store.DB.ExecContext(ctx, `INSERT INTO products(id,code,display_name) VALUES (1,'soka','Soka')`); err != nil {
		t.Fatal(err)
	}
	if _, err := store.DB.ExecContext(ctx, `INSERT INTO subscribers(id,msisdn) VALUES (1,'255700000001')`); err != nil {
		t.Fatal(err)
	}
	if _, err := store.DB.ExecContext(ctx, `INSERT INTO payment_attempts(product_id,subscriber_id,external_request_id,payment_kind,channel,amount_minor,currency,state,requested_at) VALUES (1,1,'PU-E2E-1','POINT_PURCHASE','SMS',300,'TZS','PENDING',UTC_TIMESTAMP(6))`); err != nil {
		t.Fatal(err)
	}
	body := `{"requestId":"PU-E2E-1","requestParam":{"data":[{"name":"Msisdn","value":"255700000001"},{"name":"Type","value":"Payment"},{"name":"ChargeAmount","value":"300"},{"name":"BillingId","value":"B-E2E"}]}}`
	event, err := callbacks.ParseJSON("soka", []byte(body))
	if err != nil {
		t.Fatal(err)
	}
	callbackID, err := store.Save(ctx, event)
	if err != nil {
		t.Fatal(err)
	}
	relayCtx, stopRelay := context.WithCancel(ctx)
	defer stopRelay()
	relayDone := make(chan error, 1)
	reg := &obs.Registry{}
	relayMetrics, consumerMetrics := relay.NewMetrics(reg), messaging.NewConsumerMetrics(reg)
	go func() {
		relayDone <- (relay.Relay{DB: store.DB, AMQPURL: amqpURL, Log: logger, Metrics: relayMetrics}).Run(relayCtx)
	}()
	waitDB(t, ctx, store.DB, `SELECT COUNT(*) FROM callback_outbox WHERE event_id=? AND published_at IS NOT NULL`, callbackID, 1)
	if _, err := store.DB.ExecContext(ctx, `CREATE TRIGGER fail_e2e_message BEFORE INSERT ON outbound_messages FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected worker failure'`); err != nil {
		t.Fatal(err)
	}
	defer store.DB.ExecContext(context.Background(), `DROP TRIGGER IF EXISTS fail_e2e_message`)
	workerCtx, stopWorker := context.WithCancel(ctx)
	defer stopWorker()
	workerDone := make(chan error, 1)
	go func() {
		workerDone <- (sokapoints.Consumer{Processor: sokapoints.Processor{DB: store.DB}, AMQPURL: amqpURL, Log: logger, Metrics: consumerMetrics}).Run(workerCtx)
	}()
	time.Sleep(2500 * time.Millisecond)
	var awards int
	if err := store.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM point_ledger`).Scan(&awards); err != nil || awards != 0 {
		t.Fatalf("failed transaction left %d awards: %v", awards, err)
	}
	if _, err := store.DB.ExecContext(ctx, `DROP TRIGGER fail_e2e_message`); err != nil {
		t.Fatal(err)
	}
	waitDB(t, ctx, store.DB, `SELECT COUNT(*) FROM processed_events WHERE callback_event_id=?`, callbackID, 1)
	assertOneFinancialEffect(t, ctx, store.DB)
	// Reopen the outbox as if a relay crashed after broker confirmation but before its DB mark.
	if _, err := store.DB.ExecContext(ctx, `UPDATE callback_outbox SET published_at=NULL,next_attempt_at=UTC_TIMESTAMP(6) WHERE event_id=?`, callbackID); err != nil {
		t.Fatal(err)
	}
	waitDB(t, ctx, store.DB, `SELECT COUNT(*) FROM callback_outbox WHERE event_id=? AND published_at IS NOT NULL`, callbackID, 1)
	time.Sleep(500 * time.Millisecond)
	assertOneFinancialEffect(t, ctx, store.DB)
	// A malformed broker message is rejected to the visible dead-letter queue.
	if err := ch.PublishWithContext(ctx, "yas.callbacks", "soka.Payment", false, false, amqp.Publishing{DeliveryMode: amqp.Persistent, MessageId: "invalid-e2e-id", Body: []byte(`{}`)}); err != nil {
		t.Fatal(err)
	}
	deadline := time.Now().Add(10 * time.Second)
	for time.Now().Before(deadline) {
		info, err := ch.QueueInspect("yas.soka.point-purchases.dead")
		if err != nil {
			t.Fatal(err)
		}
		if info.Messages > 0 {
			break
		}
		time.Sleep(100 * time.Millisecond)
	}
	dead, ok, err := ch.Get("yas.soka.point-purchases.dead", false)
	if err != nil || !ok || dead.MessageId != "invalid-e2e-id" {
		t.Fatalf("dead letter missing: ok=%v id=%q err=%v", ok, dead.MessageId, err)
	}
	if err := dead.Ack(false); err != nil {
		t.Fatal(err)
	}
	// The run above exercised each consumer outcome and two relay publishes.
	const consumer = "soka-point-purchase"
	if consumerMetrics.Messages.Value(consumer, messaging.OutcomeRetry) < 1 ||
		consumerMetrics.Messages.Value(consumer, messaging.OutcomeInvalidID) != 1 ||
		consumerMetrics.Messages.Value(consumer, messaging.OutcomeProcessed) < 2 ||
		consumerMetrics.Connected.Value(consumer) != 1 ||
		relayMetrics.Published.Value("soka", "Payment") != 2 || relayMetrics.Connected.Value() != 1 {
		w := httptest.NewRecorder()
		reg.Handler().ServeHTTP(w, httptest.NewRequest("GET", "/metrics", nil))
		t.Fatalf("unexpected metrics:\n%s", w.Body.String())
	}
	age, err := (relay.Relay{DB: store.DB}).OldestPendingAge(ctx)
	if err != nil || age != 0 {
		t.Fatalf("oldest pending age %v: %v", age, err)
	}
	stopWorker()
	stopRelay()
	select {
	case err := <-workerDone:
		if err != nil {
			t.Fatal(err)
		}
		if consumerMetrics.Connected.Value(consumer) != 0 {
			t.Fatal("consumer still reported connected after stop")
		}
	case <-time.After(5 * time.Second):
		t.Fatal("worker did not stop")
	}
	select {
	case err := <-relayDone:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("relay did not stop")
	}
}

func waitDB(t *testing.T, ctx context.Context, db *sql.DB, query string, id int64, want int) {
	t.Helper()
	for ctx.Err() == nil {
		var n int
		if err := db.QueryRowContext(ctx, query, id).Scan(&n); err != nil {
			t.Fatal(err)
		}
		if n == want {
			return
		}
		time.Sleep(100 * time.Millisecond)
	}
	t.Fatal("timed out waiting for database state")
}

func assertOneFinancialEffect(t *testing.T, ctx context.Context, db *sql.DB) {
	t.Helper()
	for _, q := range []string{
		`SELECT COUNT(*) FROM point_ledger`,
		`SELECT COUNT(*) FROM payment_events`,
		`SELECT COUNT(*) FROM processed_events`,
		`SELECT COUNT(*) FROM outbound_messages`,
	} {
		var n int
		if err := db.QueryRowContext(ctx, q).Scan(&n); err != nil || n != 1 {
			t.Fatalf("%s: count=%d err=%v", q, n, err)
		}
	}
	var state string
	if err := db.QueryRowContext(ctx, `SELECT state FROM payment_attempts WHERE external_request_id='PU-E2E-1'`).Scan(&state); err != nil || state != "SUCCESS" {
		t.Fatalf("payment state %s: %v", state, err)
	}
	var points int64
	if err := db.QueryRowContext(ctx, `SELECT delta_points FROM point_ledger LIMIT 1`).Scan(&points); err != nil || points != 300 {
		t.Fatalf("points %d: %v", points, err)
	}
}
