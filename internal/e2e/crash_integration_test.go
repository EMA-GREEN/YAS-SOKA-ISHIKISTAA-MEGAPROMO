// Package e2e runs the relay and Soka purchase workers as separate processes and
// kills them without warning while purchases flow, then checks for lost or
// repeated financial effects.
package e2e

import (
	"bytes"
	"context"
	"database/sql"
	"fmt"
	"log/slog"
	"math/rand/v2"
	"os"
	"os/exec"
	"os/signal"
	"strings"
	"sync"
	"syscall"
	"testing"
	"time"

	driver "github.com/go-sql-driver/mysql"
	amqp "github.com/rabbitmq/amqp091-go"
	"yas-modernization/internal/callbacks"
	"yas-modernization/internal/messaging"
	"yas-modernization/internal/relay"
	mysqlstore "yas-modernization/internal/store/mysql"
	"yas-modernization/internal/workers/sokamo"
	"yas-modernization/internal/workers/sokapoints"
)

const roleEnv = "YAS_E2E_ROLE"

var roles = []string{"relay", "mo", "points"}

// TestMain lets the test binary re-execute itself as one of the deployed
// processes, using the same components as the matching cmd entry point.
func TestMain(m *testing.M) {
	if role := os.Getenv(roleEnv); role != "" {
		os.Exit(runRole(role))
	}
	os.Exit(m.Run())
}

func runRole(role string) int {
	log := slog.New(slog.NewJSONHandler(os.Stderr, nil))
	store, err := mysqlstore.Open(os.Getenv("MYSQL_DSN"))
	if err != nil {
		log.Error("database unavailable", "error", err)
		return 1
	}
	defer store.DB.Close()
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()
	url := os.Getenv("AMQP_URL")
	switch role {
	case "relay":
		err = relay.Relay{DB: store.DB, AMQPURL: url, Log: log}.Run(ctx)
	case "mo":
		err = sokamo.Consumer{Processor: sokamo.Processor{DB: store.DB}, AMQPURL: url, Log: log}.Run(ctx)
	case "points":
		err = sokapoints.Consumer{Processor: sokapoints.Processor{DB: store.DB}, AMQPURL: url, Log: log}.Run(ctx)
	default:
		log.Error("unknown role", "role", role)
		return 2
	}
	if err != nil {
		log.Error("process stopped", "role", role, "error", err)
		return 1
	}
	return 0
}

type process struct {
	role string
	cmd  *exec.Cmd
	done chan struct{}
}

type supervisor struct {
	t      *testing.T
	env    []string
	mu     sync.Mutex
	procs  map[string]*process
	logs   bytes.Buffer
	kills  map[string]int
	exited map[string]int
}

func (s *supervisor) start(role string) {
	s.t.Helper()
	cmd := exec.Command(os.Args[0], "-test.run=^$")
	cmd.Env = append(append([]string{}, s.env...), roleEnv+"="+role)
	cmd.Stdout = &lockedWriter{s: s}
	cmd.Stderr = cmd.Stdout
	if err := cmd.Start(); err != nil {
		s.t.Fatal(role, err)
	}
	p := &process{role: role, cmd: cmd, done: make(chan struct{})}
	go func() {
		cmd.Wait()
		close(p.done)
	}()
	s.mu.Lock()
	s.procs[role] = p
	s.mu.Unlock()
}

// kill sends SIGKILL, so no deferred cleanup, ack, or database mark runs.
func (s *supervisor) kill(role string) {
	s.mu.Lock()
	p := s.procs[role]
	s.kills[role]++
	s.mu.Unlock()
	p.cmd.Process.Kill()
	<-p.done
}

func (s *supervisor) stopAll() {
	s.mu.Lock()
	procs := make([]*process, 0, len(s.procs))
	for _, p := range s.procs {
		procs = append(procs, p)
	}
	s.mu.Unlock()
	for _, p := range procs {
		p.cmd.Process.Signal(syscall.SIGTERM)
	}
	for _, p := range procs {
		select {
		case <-p.done:
			if code := p.cmd.ProcessState.ExitCode(); code != 0 {
				s.mu.Lock()
				s.exited[p.role] = code
				s.mu.Unlock()
			}
		case <-time.After(10 * time.Second):
			p.cmd.Process.Kill()
			<-p.done
			s.t.Errorf("%s ignored SIGTERM", p.role)
		}
	}
}

type lockedWriter struct{ s *supervisor }

func (w *lockedWriter) Write(b []byte) (int, error) {
	w.s.mu.Lock()
	defer w.s.mu.Unlock()
	if w.s.logs.Len() < 1<<20 {
		w.s.logs.Write(b)
	}
	return len(b), nil
}

var tiers = []struct {
	keyword        string
	amount, points int64
}{{"P300", 300, 300}, {"P600", 500, 600}, {"P1500", 1000, 1500}, {"P2500", 2000, 2500}, {"P10000", 5000, 10000}}

func TestCrashRecoveryIntegration(t *testing.T) {
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
	ctx, cancel := context.WithTimeout(context.Background(), 4*time.Minute)
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
	reset(t, ctx, store.DB, ch)

	const subscribers = 40
	seed := rand.Uint64()
	rng := rand.New(rand.NewPCG(seed, seed))
	t.Logf("chaos seed %d", seed)
	if _, err := store.DB.ExecContext(ctx, `INSERT INTO products(id,code,display_name) VALUES (1,'soka','Soka')`); err != nil {
		t.Fatal(err)
	}
	wantPoints := make(map[string]int64, subscribers)
	for i := 1; i <= subscribers; i++ {
		msisdn := fmt.Sprintf("2557000%05d", i)
		wantPoints[msisdn] = tiers[i%len(tiers)].points
		if _, err := store.DB.ExecContext(ctx, `INSERT INTO subscribers(id,msisdn) VALUES (?,?)`, i, msisdn); err != nil {
			t.Fatal(err)
		}
		if _, err := store.DB.ExecContext(ctx, `INSERT INTO product_subscriptions(product_id,subscriber_id,state,billing_segment,started_at)
			VALUES (1,?,'ACTIVE','NORMAL',UTC_TIMESTAMP(6))`, i); err != nil {
			t.Fatal(err)
		}
	}

	sup := &supervisor{t: t, procs: map[string]*process{}, kills: map[string]int{}, exited: map[string]int{},
		env: append(os.Environ(), "MYSQL_DSN="+dsn, "AMQP_URL="+amqpURL)}
	for _, role := range roles {
		sup.start(role)
	}
	defer func() {
		if t.Failed() {
			sup.mu.Lock()
			t.Logf("process output:\n%s", tail(sup.logs.String(), 8000))
			sup.mu.Unlock()
		}
	}()

	// The customer sends MOs while processes are killed.
	feedDone := make(chan struct{})
	go func() {
		defer close(feedDone)
		for i := 1; i <= subscribers && ctx.Err() == nil; i++ {
			msisdn := fmt.Sprintf("2557000%05d", i)
			body := fmt.Sprintf(`{"requestId":"MO-CRASH-%d","requestParam":{"data":[{"name":"Msisdn","value":%q},{"name":"Type","value":"InteractiveResponse"},{"name":"CustomerResponse","value":%q},{"name":"TransactionId","value":"T-%d"}]}}`,
				i, msisdn, tiers[i%len(tiers)].keyword, i)
			save(t, ctx, store, body)
			time.Sleep(250 * time.Millisecond)
		}
	}()

	// The provider answers each pending charge, sometimes twice, while processes are killed.
	providerCtx, stopProvider := context.WithCancel(ctx)
	providerDone := make(chan struct{})
	go func() {
		defer close(providerDone)
		answered := map[string]string{}
		for providerCtx.Err() == nil {
			rows, err := store.DB.QueryContext(providerCtx, `SELECT pa.external_request_id, pa.amount_minor, s.msisdn
				FROM payment_attempts pa JOIN subscribers s ON s.id=pa.subscriber_id`)
			if err != nil {
				return
			}
			for rows.Next() {
				var requestID, msisdn string
				var amount int64
				if err := rows.Scan(&requestID, &amount, &msisdn); err != nil {
					break
				}
				if _, ok := answered[requestID]; !ok {
					answered[requestID] = fmt.Sprintf(`{"requestId":%q,"requestParam":{"data":[{"name":"Msisdn","value":%q},{"name":"Type","value":"Payment"},{"name":"ChargeAmount","value":"%d"},{"name":"BillingId","value":"B-%s"}]}}`,
						requestID, msisdn, amount, requestID)
					save(t, providerCtx, store, answered[requestID])
				}
			}
			rows.Close()
			for _, body := range answered {
				if rand.IntN(20) == 0 {
					save(t, providerCtx, store, body)
				}
				break
			}
			time.Sleep(200 * time.Millisecond)
		}
	}()

	// Kill a random process at random moments and restart it. Occasionally reopen
	// a published outbox row, as if the relay died after broker confirmation.
	chaosUntil := time.Now().Add(25 * time.Second)
	replays := 0
	for time.Now().Before(chaosUntil) && ctx.Err() == nil {
		time.Sleep(time.Duration(150+rng.IntN(500)) * time.Millisecond)
		role := roles[rng.IntN(len(roles))]
		sup.kill(role)
		time.Sleep(time.Duration(rng.IntN(300)) * time.Millisecond)
		sup.start(role)
		if rng.IntN(3) == 0 {
			result, err := store.DB.ExecContext(ctx, `UPDATE callback_outbox SET published_at=NULL, next_attempt_at=UTC_TIMESTAMP(6)
				WHERE published_at IS NOT NULL ORDER BY RAND() LIMIT 1`)
			if err != nil {
				t.Fatal(err)
			}
			if n, _ := result.RowsAffected(); n == 1 {
				replays++
			}
		}
	}
	<-feedDone
	t.Logf("kills %v, forced outbox replays %d", sup.kills, replays)
	for _, role := range roles {
		if sup.kills[role] < 3 {
			t.Fatalf("only %d kills of %s; chaos window too small", sup.kills[role], role)
		}
	}

	// After the chaos stops, everything must converge. Relay leases from killed
	// processes expire after 30 seconds, so allow for that.
	converged := waitFor(ctx, 150*time.Second, func() bool {
		return count(t, ctx, store.DB, `SELECT COUNT(*) FROM point_ledger`) == subscribers &&
			count(t, ctx, store.DB, `SELECT COUNT(*) FROM callback_outbox WHERE published_at IS NULL`) == 0 &&
			queued(t, ch, "yas.soka.point-mo", "yas.soka.point-purchases") == 0
	})
	stopProvider()
	<-providerDone
	if !converged {
		t.Fatalf("did not converge: awards=%d unpublished=%d",
			count(t, ctx, store.DB, `SELECT COUNT(*) FROM point_ledger`),
			count(t, ctx, store.DB, `SELECT COUNT(*) FROM callback_outbox WHERE published_at IS NULL`))
	}
	// Let any in-flight duplicate deliveries finish before checking for repeats.
	time.Sleep(3 * time.Second)
	sup.stopAll()
	for role, code := range sup.exited {
		t.Errorf("%s exited with %d after SIGTERM", role, code)
	}

	for query, want := range map[string]int{
		`SELECT COUNT(*) FROM callback_events WHERE event_kind='InteractiveResponse'`:                     subscribers,
		`SELECT COUNT(*) FROM callback_events WHERE event_kind='Payment'`:                                 subscribers,
		`SELECT COUNT(*) FROM interactions`:                                                               subscribers,
		`SELECT COUNT(*) FROM payment_attempts`:                                                           subscribers,
		`SELECT COUNT(*) FROM payment_attempts WHERE state='SUCCESS'`:                                     subscribers,
		`SELECT COUNT(*) FROM charge_intents`:                                                             subscribers,
		`SELECT COUNT(*) FROM payment_events`:                                                             subscribers,
		`SELECT COUNT(*) FROM point_ledger`:                                                               subscribers,
		`SELECT COUNT(*) FROM outbound_messages`:                                                          subscribers,
		`SELECT COUNT(*) FROM processed_events WHERE consumer_name='soka-point-mo'`:                       subscribers,
		`SELECT COUNT(*) FROM processed_events WHERE consumer_name='soka-point-purchase'`:                 subscribers,
		`SELECT COUNT(*) FROM callback_outbox WHERE dead_lettered_at IS NOT NULL OR published_at IS NULL`: 0,
	} {
		if got := count(t, ctx, store.DB, query); got != want {
			t.Errorf("%s = %d, want %d", query, got, want)
		}
	}
	rows, err := store.DB.QueryContext(ctx, `SELECT s.msisdn, SUM(pl.delta_points) FROM point_ledger pl JOIN subscribers s ON s.id=pl.subscriber_id GROUP BY s.msisdn`)
	if err != nil {
		t.Fatal(err)
	}
	defer rows.Close()
	seen := 0
	for rows.Next() {
		var msisdn string
		var points int64
		if err := rows.Scan(&msisdn, &points); err != nil {
			t.Fatal(err)
		}
		seen++
		if points != wantPoints[msisdn] {
			t.Errorf("%s has %d points, want %d", msisdn, points, wantPoints[msisdn])
		}
	}
	if seen != subscribers {
		t.Errorf("%d subscribers awarded, want %d", seen, subscribers)
	}
	for _, queue := range []string{"yas.soka.point-mo", "yas.soka.point-mo.dead", "yas.soka.point-purchases", "yas.soka.point-purchases.dead"} {
		info, err := ch.QueueInspect(queue)
		if err != nil {
			t.Fatal(err)
		}
		if info.Messages != 0 {
			t.Errorf("%s holds %d messages", queue, info.Messages)
		}
	}
}

func reset(t *testing.T, ctx context.Context, db *sql.DB, ch *amqp.Channel) {
	t.Helper()
	if _, err := db.ExecContext(ctx, `DROP TRIGGER IF EXISTS fail_e2e_message`); err != nil {
		t.Fatal(err)
	}
	for _, table := range []string{"processed_events", "outbound_message_events", "outbound_messages", "point_ledger", "payment_events", "charge_intents", "payment_attempts", "interactions", "product_subscriptions", "callback_outbox", "callback_events", "products", "subscribers"} {
		if _, err := db.ExecContext(ctx, "DELETE FROM "+table); err != nil {
			t.Fatal(table, err)
		}
	}
	if err := ch.ExchangeDeclare("yas.callbacks", "topic", true, false, false, false, nil); err != nil {
		t.Fatal(err)
	}
	if err := messaging.DeclareSokaPointPurchases(ch); err != nil {
		t.Fatal(err)
	}
	if err := messaging.DeclareSokaMO(ch); err != nil {
		t.Fatal(err)
	}
	for _, queue := range []string{"yas.soka.point-mo", "yas.soka.point-mo.dead", "yas.soka.point-purchases", "yas.soka.point-purchases.dead"} {
		if _, err := ch.QueuePurge(queue, false); err != nil {
			t.Fatal(queue, err)
		}
	}
	// The audit queue has no consumer here; empty it so repeated runs stay small.
	if _, err := ch.QueueDeclare("yas.callback.events", true, false, false, false, nil); err != nil {
		t.Fatal(err)
	}
	if _, err := ch.QueuePurge("yas.callback.events", false); err != nil {
		t.Fatal(err)
	}
}

func save(t *testing.T, ctx context.Context, store *mysqlstore.Store, body string) {
	event, err := callbacks.ParseJSON("soka", []byte(body))
	if err != nil {
		t.Error(err)
		return
	}
	if _, err := store.Save(ctx, event); err != nil && ctx.Err() == nil {
		t.Error(err)
	}
}

func count(t *testing.T, ctx context.Context, db *sql.DB, query string) int {
	t.Helper()
	var n int
	if err := db.QueryRowContext(ctx, query).Scan(&n); err != nil {
		t.Fatal(query, err)
	}
	return n
}

func queued(t *testing.T, ch *amqp.Channel, queues ...string) int {
	t.Helper()
	total := 0
	for _, queue := range queues {
		info, err := ch.QueueInspect(queue)
		if err != nil {
			t.Fatal(queue, err)
		}
		total += info.Messages
	}
	return total
}

func waitFor(ctx context.Context, limit time.Duration, ok func() bool) bool {
	deadline := time.Now().Add(limit)
	for time.Now().Before(deadline) && ctx.Err() == nil {
		if ok() {
			return true
		}
		time.Sleep(250 * time.Millisecond)
	}
	return false
}

func tail(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[len(s)-n:]
}
