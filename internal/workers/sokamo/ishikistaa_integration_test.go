package sokamo

import (
	"context"
	"errors"
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

func TestIshikistaaIntegration(t *testing.T) {
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
	// 19:30 UTC is 22:30 in Dar es Salaam: golden for Soka, not for Ishikistaa.
	now := time.Date(2026, 10, 28, 19, 30, 0, 0, time.UTC)
	exec(`INSERT INTO products(id,code,display_name) VALUES (1,'soka','Soka'),(2,'ishikistaa','Ishikistaa')`)
	exec(`INSERT INTO questions(id,product_id,question_text,answer_key,points) VALUES (51,2,'Q-ISHI','A',100)`)
	ishi := sokagame.Ishikistaa
	mo := Processor{DB: store.DB, Now: func() time.Time { return now }, Profile: ishi}
	pay := sokapoints.Processor{DB: store.DB, Now: func() time.Time { return now }, Profile: ishi}
	soka := Processor{DB: store.DB, Now: func() time.Time { return now }}
	seq := 0
	save := func(body string) int64 {
		t.Helper()
		event, err := callbacks.ParseJSON("ishikistaa", []byte(body))
		if err != nil {
			t.Fatal(err)
		}
		id, err := store.Save(ctx, event)
		if err != nil {
			t.Fatal(err)
		}
		return id
	}
	send := func(msisdn, kind string, extra map[string]string) int64 {
		t.Helper()
		seq++
		data := fmt.Sprintf(`{"name":"Msisdn","value":%q},{"name":"Type","value":%q}`, msisdn, kind)
		for k, v := range extra {
			data += fmt.Sprintf(`,{"name":%q,"value":%q}`, k, v)
		}
		id := save(fmt.Sprintf(`{"requestId":"ISHI-%d","requestParam":{"data":[%s]}}`, seq, data))
		if err := mo.Process(ctx, id); err != nil {
			t.Fatalf("%s %v: %v", kind, extra, err)
		}
		return id
	}
	last := func(msisdn string) (string, string) {
		t.Helper()
		var kind, body string
		if err := store.DB.QueryRowContext(ctx, `SELECT m.message_kind, m.body_text FROM outbound_messages m JOIN subscribers s ON s.id=m.subscriber_id
			WHERE s.msisdn=? ORDER BY m.id DESC LIMIT 1`, msisdn).Scan(&kind, &body); err != nil {
			t.Fatal(msisdn, err)
		}
		return kind, body
	}
	state := func(msisdn string) string {
		var s string
		_ = store.DB.QueryRowContext(ctx, `SELECT ps.state FROM product_subscriptions ps JOIN subscribers s ON s.id=ps.subscriber_id WHERE s.msisdn=? AND ps.product_id=2`, msisdn).Scan(&s)
		return s
	}
	renewals := func(msisdn, activity, target string) int {
		return count(`SELECT COUNT(*) FROM renewal_requests r JOIN subscribers s ON s.id=r.subscriber_id
			WHERE s.msisdn=? AND r.activity_type=? AND r.target LIKE ? AND r.product_id=2`, msisdn, activity, target)
	}
	const user, staff = "255700000601", "255715123601"

	// The Soka engine ignores Ishikistaa callbacks.
	if err := soka.Process(ctx, save(`{"requestId":"ISHI-SOKA","requestParam":{"data":[{"name":"Msisdn","value":"255700000699"},{"name":"Type","value":"InteractiveResponse"},{"name":"CustomerResponse","value":"A"}]}}`)); !errors.Is(err, ErrNotApplicable) {
		t.Fatalf("Soka engine must skip Ishikistaa: %v", err)
	}

	// Activation: active subscription, plain welcome, both renewal endpoints.
	send(user, "Activation", map[string]string{"SubscriptionStatus": "A"})
	if state(user) != "ACTIVE" || renewals(user, "SUBSCRIPTION", "%") != 2 {
		t.Fatal("activation")
	}
	if _, body := last(user); body != "Jibu swali ushinde zaidi ya 12,000,000 msimu huu. Anza sasa!Q-ISHI." {
		t.Fatalf("welcome %q", body)
	}
	// Staff are still reported, but only to subs-manage.
	send(staff, "Activation", map[string]string{"SubscriptionStatus": "A"})
	if renewals(staff, "SUBSCRIPTION", "SUBS_MANAGE") != 1 || renewals(staff, "SUBSCRIPTION", "%") != 1 {
		t.Fatal("staff renewal endpoints")
	}

	// A paid answer at 22:30 is not golden for Ishikistaa, uses its offer, and
	// predict-looking text is charged as an answer.
	answer := send(user, "InteractiveResponse", map[string]string{"CustomerResponse": "a"})
	var request, offer string
	var points int
	if err := store.DB.QueryRowContext(ctx, `SELECT pa.external_request_id, ci.offer_code, a.points_on_success FROM answers a
		JOIN payment_attempts pa ON pa.id=a.payment_attempt_id JOIN charge_intents ci ON ci.payment_attempt_id=pa.id
		JOIN interactions i ON i.id=a.interaction_id WHERE i.external_request_id=?`, fmt.Sprintf("ISHI-%d", seq)).Scan(&request, &offer, &points); err != nil {
		t.Fatal(err)
	}
	if points != 100 || offer != "017008600007" || strings.ContainsAny(request, "ARWarw") || request != fmt.Sprintf("%d%s", answer, user) {
		t.Fatalf("answer points=%d offer=%s request=%s", points, offer, request)
	}
	payment := func(req, charged, reason, billing string) {
		t.Helper()
		id := save(fmt.Sprintf(`{"requestId":%q,"requestParam":{"data":[{"name":"Msisdn","value":%q},{"name":"Type","value":"Payment"},{"name":"ChargeAmount","value":%q},{"name":"Reason","value":%q},{"name":"BillingId","value":%q}]}}`,
			req, user, charged, reason, billing))
		if err := pay.Process(ctx, id); err != nil {
			t.Fatal(err)
		}
	}
	// Low balance falls back to 45; its success is credited, which PHP's
	// LOW_BAANCE_FAILED typo prevents.
	payment(request, "99", "Low balance", "")
	var fallback string
	if err := store.DB.QueryRowContext(ctx, `SELECT external_request_id FROM payment_attempts WHERE amount_minor=45 AND fallback_of_attempt_id IS NOT NULL`).Scan(&fallback); err != nil {
		t.Fatal("fallback charge", err)
	}
	if count(`SELECT COUNT(*) FROM charge_intents WHERE offer_code='017008600007'`) != 2 {
		t.Fatal("the fallback uses the Ishikistaa offer")
	}
	payment(fallback, "45", "", "B-ISHI")
	if count(`SELECT COALESCE(SUM(delta_points),0) FROM point_ledger WHERE product_id=2`) != 100 {
		t.Fatal("fallback success must credit the answer")
	}
	if count(`SELECT answer_level FROM product_subscriptions ps JOIN subscribers s ON s.id=ps.subscriber_id WHERE s.msisdn=?`, user) != 0 {
		t.Fatal("Ishikistaa answers do not raise the level")
	}
	if kind, body := last(user); kind != sokagame.KindQuestion || body != "Una pointi 100! Q-ISHI." {
		t.Fatalf("next question %s %q", kind, body)
	}
	charges := count(`SELECT COUNT(*) FROM charge_intents`)
	send(user, "InteractiveResponse", map[string]string{"CustomerResponse": "M2"})
	if count(`SELECT COUNT(*) FROM charge_intents`) != charges+1 || count(`SELECT COUNT(*) FROM outbound_messages WHERE message_kind LIKE 'PREDICT%'`) != 0 {
		t.Fatal("M2 is a charged answer in Ishikistaa")
	}

	// Free credits use the Ishikistaa layout.
	exec(`UPDATE product_subscriptions ps JOIN subscribers s ON s.id=ps.subscriber_id SET ps.free_credits=2 WHERE s.msisdn=?`, user)
	send(user, "InteractiveResponse", map[string]string{"CustomerResponse": "b"})
	if _, body := last(user); body != "Una pointi 125!\n KETE 1 Buree!\nQ-ISHI" {
		t.Fatalf("credit question %q", body)
	}

	// STOP confirms by SMS; inactive text gets NON_CUSTOMER; STAA reactivates.
	send(user, "InteractiveResponse", map[string]string{"CustomerResponse": "stop"})
	if kind, body := last(user); state(user) != "INACTIVE" || kind != "UNSUB_SMS" || body != ishi.UnsubSMS || renewals(user, "UNSUBSCRIPTION", "%") != 2 {
		t.Fatalf("stop %s %s", state(user), kind)
	}
	send(user, "InteractiveResponse", map[string]string{"CustomerResponse": "hello"})
	if kind, body := last(user); kind != sokagame.KindNonCustomer || body != ishi.NonCustomer {
		t.Fatalf("inactive reply %s", kind)
	}
	send(user, "InteractiveResponse", map[string]string{"CustomerResponse": " Staa "})
	if _, body := last(user); state(user) != "ACTIVE" || body != ishi.ReactivationQuestion(125, "Q-ISHI") {
		t.Fatalf("reactivation %s %q", state(user), body)
	}
	// DeActivation confirms by SMS, for a subscriber and for a stranger.
	send(user, "DeActivation", nil)
	if kind, _ := last(user); state(user) != "INACTIVE" || kind != "UNSUB_SMS" {
		t.Fatal("deactivation")
	}
	send("255700000698", "DeActivation", nil)
	if kind, _ := last("255700000698"); kind != "UNSUB_SMS" {
		t.Fatal("stranger deactivation")
	}
	// Activation of an inactive subscriber sends just the question.
	send(user, "Activation", map[string]string{"SubscriptionStatus": "A"})
	if _, body := last(user); state(user) != "ACTIVE" || body != "Q-ISHI." {
		t.Fatalf("reactivating activation %q", body)
	}
	// M-Push init: an activation attempt, confirmed only by a same-day Activation.
	const pushed = "255700000696"
	mpush, _, err := callbacks.ParseMpush("ishikistaa", []byte(`{"msisdn":"`+pushed+`","channel":"USSD"}`), now)
	if err != nil {
		t.Fatal(err)
	}
	mpushID, err := store.Save(ctx, mpush)
	if err != nil {
		t.Fatal(err)
	}
	if err := mo.Process(ctx, mpushID); err != nil {
		t.Fatal(err)
	}
	exec(`UPDATE activation_attempts SET requested_at=DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 2 DAY)`)
	send(pushed, "Activation", map[string]string{"SubscriptionStatus": "A"})
	if count(`SELECT COUNT(*) FROM activation_attempts WHERE state='PENDING' AND product_id=2`) != 1 {
		t.Fatal("an older Ishikistaa M-Push request must not be confirmed")
	}
	mpush2, _, _ := callbacks.ParseMpush("ishikistaa", []byte(`{"msisdn":"`+pushed+`","channel":"SMS"}`), now)
	mpush2ID, err := store.Save(ctx, mpush2)
	if err != nil {
		t.Fatal(err)
	}
	if err := mo.Process(ctx, mpush2ID); err != nil {
		t.Fatal(err)
	}
	send(pushed, "Activation", map[string]string{"SubscriptionStatus": "A"})
	if count(`SELECT COUNT(*) FROM activation_attempts WHERE state='CONFIRMED' AND product_id=2 AND channel='SMS'`) != 1 {
		t.Fatal("a same-day Ishikistaa M-Push request must be confirmed")
	}

	// An unknown customer gets NON_CUSTOMER and is not auto-subscribed.
	send("255700000697", "InteractiveResponse", map[string]string{"CustomerResponse": "hello"})
	if kind, _ := last("255700000697"); state("255700000697") != "INACTIVE" || kind != sokagame.KindNonCustomer {
		t.Fatal("unknown customer")
	}
}
