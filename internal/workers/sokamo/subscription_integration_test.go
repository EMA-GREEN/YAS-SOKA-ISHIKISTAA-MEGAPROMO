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
)

func TestSubscriptionLifecycleIntegration(t *testing.T) {
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
	count := func(q string, args ...any) int64 {
		t.Helper()
		var n int64
		if err := store.DB.QueryRowContext(ctx, q, args...).Scan(&n); err != nil {
			t.Fatal(q, err)
		}
		return n
	}
	text := func(q string, args ...any) string {
		t.Helper()
		var s string
		if err := store.DB.QueryRowContext(ctx, q, args...).Scan(&s); err != nil {
			t.Fatal(q, err)
		}
		return s
	}
	exec(`INSERT INTO products(id,code,display_name) VALUES (1,'soka','Soka')`)
	exec(`INSERT INTO questions(id,product_id,question_text,answer_key,points) VALUES (31,1,'Q-SUB','A',100)`)
	p := Processor{DB: store.DB, Now: func() time.Time { return time.Date(2026, 10, 28, 8, 0, 0, 0, time.UTC) }}
	seq := 0
	send := func(msisdn, kind string, extra map[string]string) int64 {
		t.Helper()
		seq++
		data := fmt.Sprintf(`{"name":"Msisdn","value":%q},{"name":"Type","value":%q}`, msisdn, kind)
		for k, v := range extra {
			data += fmt.Sprintf(`,{"name":%q,"value":%q}`, k, v)
		}
		event, err := callbacks.ParseJSON("soka", []byte(fmt.Sprintf(`{"requestId":"LC-%d","requestParam":{"data":[%s]}}`, seq, data)))
		if err != nil {
			t.Fatal(err)
		}
		id, err := store.Save(ctx, event)
		if err != nil {
			t.Fatal(err)
		}
		return id
	}
	run := func(name string, id int64, want error) {
		t.Helper()
		if err := p.Process(ctx, id); !errors.Is(err, want) && !(want == nil && err == nil) {
			t.Fatalf("%s: err=%v want %v", name, err, want)
		}
	}
	state := func(msisdn string) (string, string, int64, int64, bool) {
		t.Helper()
		var st, seg string
		var boom, level int64
		var dnd bool
		if err := store.DB.QueryRowContext(ctx, `SELECT ps.state, ps.billing_segment, ps.boom_credits, ps.answer_level, ps.do_not_disturb
			FROM product_subscriptions ps JOIN subscribers s ON s.id=ps.subscriber_id WHERE s.msisdn=?`, msisdn).Scan(&st, &seg, &boom, &level, &dnd); err != nil {
			t.Fatal(msisdn, err)
		}
		return st, seg, boom, level, dnd
	}
	renewals := func(msisdn, activity string) int64 {
		return count(`SELECT COUNT(*) FROM renewal_requests r JOIN subscribers s ON s.id=r.subscriber_id WHERE s.msisdn=? AND r.activity_type=?`, msisdn, activity)
	}
	lastMessage := func(msisdn string) (string, string) {
		t.Helper()
		var kind, body string
		if err := store.DB.QueryRowContext(ctx, `SELECT m.message_kind, m.body_text FROM outbound_messages m JOIN subscribers s ON s.id=m.subscriber_id
			WHERE s.msisdn=? ORDER BY m.id DESC LIMIT 1`, msisdn).Scan(&kind, &body); err != nil {
			t.Fatal(msisdn, err)
		}
		return kind, body
	}
	const normal, boom, staff = "255700000101", "255700000102", "255715123999"
	welcome := sokagame.WelcomeQuestion(0, "Q-SUB")

	// Ignored provider traffic.
	run("activation without status A", send(normal, "Activation", map[string]string{"SubscriptionStatus": "D"}), ErrNotApplicable)
	run("test offer", send(normal, "Activation", map[string]string{"SubscriptionStatus": "A", "OfferCode": sokagame.TestOfferCode}), ErrNotApplicable)
	if count(`SELECT COUNT(*) FROM product_subscriptions`) != 0 {
		t.Fatal("ignored traffic created a subscription")
	}

	// A new activation subscribes, reports to both renewal endpoints, and sends the welcome question.
	first := send(normal, "Activation", map[string]string{"SubscriptionStatus": "A"})
	run("activation", first, nil)
	run("activation replay", first, nil)
	if st, seg, b, _, dnd := state(normal); st != "ACTIVE" || seg != "NORMAL" || b != 0 || dnd {
		t.Fatalf("new subscription %s %s %d %v", st, seg, b, dnd)
	}
	if renewals(normal, "SUBSCRIPTION") != 2 || count(`SELECT COUNT(*) FROM subscription_events WHERE event_kind='SUBSCRIBED'`) != 1 {
		t.Fatal("subscription event or renewal requests")
	}
	if kind, body := lastMessage(normal); kind != sokagame.KindQuestion || body != welcome {
		t.Fatalf("welcome %s %q", kind, body)
	}
	// Activation while active sends a plain question and reports again, as PHP does.
	run("activation while active", send(normal, "RequestSendSms", map[string]string{"SubscriptionStatus": "A"}), nil)
	if _, body := lastMessage(normal); body != "Q-SUB." || renewals(normal, "SUBSCRIPTION") != 4 {
		t.Fatalf("active activation %q renewals=%d", body, renewals(normal, "SUBSCRIPTION"))
	}

	// A BOOM activation gets one free answer and the notification.
	run("boom activation", send(boom, "Activation", map[string]string{"SubscriptionStatus": "A", "CustomerType": "BOOM_CUSTOMER"}), nil)
	if st, seg, b, _, _ := state(boom); st != "ACTIVE" || seg != "BOOM" || b != 1 {
		t.Fatalf("boom subscription %s %s %d", st, seg, b)
	}
	if kind, _ := lastMessage(boom); kind != sokagame.KindBoomNotify {
		t.Fatalf("boom notify %s", kind)
	}
	charges := count(`SELECT COUNT(*) FROM charge_intents`)
	run("boom free answer", send(boom, "InteractiveResponse", map[string]string{"CustomerResponse": "A"}), nil)
	if count(`SELECT COUNT(*) FROM charge_intents`) != charges ||
		count(`SELECT COALESCE(SUM(delta_points),0) FROM point_ledger WHERE reason='FREE_ANSWER'`) != 100 {
		t.Fatal("free answer must credit without charging")
	}
	if _, _, b, level, _ := state(boom); b != 0 || level != 0 {
		t.Fatalf("boom credits=%d level=%d after free answer", b, level)
	}
	if _, body := lastMessage(boom); body != "Unapoint 100!Q-SUB" {
		t.Fatalf("credit question %q", body)
	}
	run("boom paid answer", send(boom, "InteractiveResponse", map[string]string{"CustomerResponse": "A"}), nil)
	if count(`SELECT COUNT(*) FROM charge_intents`) != charges+1 {
		t.Fatal("answer after the free credit must be charged")
	}
	// Normal free credits show how many remain.
	exec(`UPDATE product_subscriptions ps JOIN subscribers s ON s.id=ps.subscriber_id SET ps.free_credits=2 WHERE s.msisdn=?`, normal)
	run("credit answer", send(normal, "InteractiveResponse", map[string]string{"CustomerResponse": "b"}), nil)
	if _, body := lastMessage(normal); body != "Unapoint 25!KETE 1 Buree!Q-SUB" {
		t.Fatalf("credit question %q", body)
	}

	// STOP ends the subscription silently and reports it.
	exec(`UPDATE product_subscriptions ps JOIN subscribers s ON s.id=ps.subscriber_id SET ps.answer_level=3 WHERE s.msisdn=?`, normal)
	messages := count(`SELECT COUNT(*) FROM outbound_messages`)
	run("stop", send(normal, "InteractiveResponse", map[string]string{"CustomerResponse": "STOP"}), nil)
	if st, _, _, level, _ := state(normal); st != "INACTIVE" || level != 0 {
		t.Fatalf("after stop %s level=%d", st, level)
	}
	if renewals(normal, "UNSUBSCRIPTION") != 2 || count(`SELECT COUNT(*) FROM outbound_messages`) != messages {
		t.Fatal("stop must report and send nothing")
	}

	// Inactive: keywords still answer, other text invites, purchases do not charge, SOKA reactivates.
	run("inactive points", send(normal, "InteractiveResponse", map[string]string{"CustomerResponse": "points"}), nil)
	if kind, body := lastMessage(normal); kind != sokagame.KindPoints || !strings.HasPrefix(body, "Point zako ni 25.") {
		t.Fatalf("inactive points %s %q", kind, body)
	}
	run("inactive text", send(normal, "InteractiveResponse", map[string]string{"CustomerResponse": "A"}), nil)
	run("inactive purchase", send(normal, "InteractiveResponse", map[string]string{"CustomerResponse": "P300"}), nil)
	if kind, _ := lastMessage(normal); kind != sokagame.KindInactiveSub || count(`SELECT COUNT(*) FROM charge_intents`) != charges+1 {
		t.Fatal("inactive subscriber must be invited, not charged")
	}
	run("reactivate", send(normal, "InteractiveResponse", map[string]string{"CustomerResponse": " Soka "}), nil)
	if st, _, _, _, _ := state(normal); st != "ACTIVE" {
		t.Fatal("SOKA must reactivate")
	}
	if _, body := lastMessage(normal); body != sokagame.ReactivationQuestion(25, "Q-SUB") || renewals(normal, "SUBSCRIPTION") != 6 {
		t.Fatalf("reactivation %q", body)
	}

	// DeActivation ends an active subscription and tells a non-subscriber about Soka.
	run("deactivation", send(normal, "DeActivation", nil), nil)
	if st, _, _, _, _ := state(normal); st != "INACTIVE" {
		t.Fatal("deactivation")
	}
	run("deactivation of stranger", send("255700000199", "DeActivation", nil), nil)
	if kind, _ := lastMessage("255700000199"); kind != sokagame.KindNonCustomer || renewals("255700000199", "UNSUBSCRIPTION") != 2 {
		t.Fatal("non-customer deactivation")
	}

	// A reactivating activation keeps the points in the welcome.
	run("reactivating activation", send(normal, "Activation", map[string]string{"SubscriptionStatus": "A"}), nil)
	if _, body := lastMessage(normal); body != sokagame.WelcomeQuestion(25, "Q-SUB") {
		t.Fatalf("reactivating welcome %q", body)
	}
	if count(`SELECT COUNT(*) FROM subscription_events WHERE event_kind='REACTIVATED'`) != 2 {
		t.Fatal("reactivation events")
	}

	// Staff skip the renewal system and are marked do-not-disturb.
	run("staff activation", send(staff, "Activation", map[string]string{"SubscriptionStatus": "A"}), nil)
	if _, _, _, _, dnd := state(staff); !dnd || renewals(staff, "SUBSCRIPTION") != 0 {
		t.Fatal("staff subscription")
	}

	// M-Push subscription-init: an activation request for a new customer, confirmed
	// by the provider's later Activation callback.
	const pushed = "255700000177"
	mpush, _, err := callbacks.ParseMpush("soka", []byte(`{"msisdn":"`+pushed+`","channel":"USSD","ussd_data":"*150*1#"}`), time.Now())
	if err != nil {
		t.Fatal(err)
	}
	mpushID, err := store.Save(ctx, mpush)
	if err != nil {
		t.Fatal(err)
	}
	run("mpush", mpushID, nil)
	run("mpush replay", mpushID, nil)
	if st, _, _, _, _ := state(pushed); st != "INACTIVE" {
		t.Fatal("M-Push must create an inactive subscription")
	}
	if text(`SELECT CONCAT(a.state,'/',a.channel,'/',a.ussd_data,'/',a.external_request_id) FROM activation_attempts a JOIN subscribers s ON s.id=a.subscriber_id WHERE s.msisdn=?`, pushed) !=
		fmt.Sprintf("PENDING/USSD/*150*1#/MP%d%s", mpushID, pushed) {
		t.Fatal("activation attempt")
	}
	run("activation confirms push", send(pushed, "Activation", map[string]string{"SubscriptionStatus": "A"}), nil)
	if st, _, _, _, _ := state(pushed); st != "ACTIVE" || count(`SELECT COUNT(*) FROM activation_attempts WHERE state='CONFIRMED'`) != 1 {
		t.Fatal("Activation must activate and confirm the M-Push request")
	}

	// An unknown customer's MO creates an inactive subscription and an invitation.
	run("stranger MO", send("255700000188", "InteractiveResponse", map[string]string{"CustomerResponse": "hello"}), nil)
	if st, _, _, _, _ := state("255700000188"); st != "INACTIVE" {
		t.Fatal("stranger subscription")
	}
	if kind, body := lastMessage("255700000188"); kind != sokagame.KindInactiveSub || body != sokagame.InactiveSub {
		t.Fatal("stranger invitation")
	}
	if text(`SELECT interaction_kind FROM interactions WHERE external_request_id=?`, fmt.Sprintf("LC-%d", seq)) != "MO" {
		t.Fatal("stranger interaction")
	}
}
