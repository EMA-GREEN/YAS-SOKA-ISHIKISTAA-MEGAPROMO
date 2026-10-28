//go:build system

// Package system runs black-box customer journeys against a running stack:
// callbacks go in over HTTP as the provider sends them, and assertions read
// what reached the provider (the fake Tigo provider's request log) and the
// database. It targets the local Compose stack by default, or a staging stack
// through the same environment variables.
//
//	SYSTEM_CALLBACK_URL    callback server, e.g. http://127.0.0.1:18080
//	SYSTEM_CALLBACK_TOKEN  ingress token
//	SYSTEM_PROVIDER_URL    fake provider, e.g. http://127.0.0.1:18090
//	SYSTEM_MYSQL_DSN       verification access to a yas_test_* or yas_staging_* database
//	SYSTEM_OPS_URL         optional, base for ops ports, e.g. http://127.0.0.1
//	SYSTEM_BOOM_AWARD_CMD  optional shell command that runs one BOOM award round; %s is the round
//
// Only synthetic MSISDNs (2557999…) are used, unique per run.
package system

import (
	"bytes"
	"context"
	"database/sql"
	"encoding/json"
	"fmt"
	"io"
	"math/rand/v2"
	"net/http"
	"os"
	"os/exec"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	driver "github.com/go-sql-driver/mysql"
)

var (
	callbackURL, token, providerURL, opsURL string
	db                                      *sql.DB
	runPrefix                               = fmt.Sprintf("%02d", rand.IntN(100))
	caseSeq                                 atomic.Int64
)

func TestMain(m *testing.M) {
	callbackURL, token, providerURL = os.Getenv("SYSTEM_CALLBACK_URL"), os.Getenv("SYSTEM_CALLBACK_TOKEN"), os.Getenv("SYSTEM_PROVIDER_URL")
	opsURL = os.Getenv("SYSTEM_OPS_URL")
	dsn := os.Getenv("SYSTEM_MYSQL_DSN")
	if callbackURL == "" || providerURL == "" || dsn == "" {
		fmt.Println("system tests skipped: set SYSTEM_CALLBACK_URL, SYSTEM_PROVIDER_URL, and SYSTEM_MYSQL_DSN")
		os.Exit(0)
	}
	cfg, err := driver.ParseDSN(dsn)
	if err != nil || !(strings.HasPrefix(cfg.DBName, "yas_test_") || strings.HasPrefix(cfg.DBName, "yas_staging_")) {
		fmt.Println("SYSTEM_MYSQL_DSN must select a yas_test_ or yas_staging_ database")
		os.Exit(2)
	}
	if db, err = sql.Open("mysql", dsn); err != nil {
		fmt.Println("database:", err)
		os.Exit(2)
	}
	if err := seed(); err != nil {
		fmt.Println("seed:", err)
		os.Exit(2)
	}
	code := m.Run()
	db.Close()
	os.Exit(code)
}

// seed adds the products and questions the journeys need. Every question's
// answer is A, so whichever question is drawn, "A" is correct.
func seed() error {
	for _, q := range []string{
		`INSERT IGNORE INTO products(id,code,display_name) VALUES (1,'soka','Soka'),(2,'ishikistaa','Ishikistaa'),(3,'megapromo','MegaPromo')`,
		`INSERT IGNORE INTO questions(id,product_id,question_text,answer_key,points) VALUES
			(1,1,'Soka Q1?','A',100),(2,1,'Soka Q2?','A',100),(11,2,'Ishi Q1?','A',100),(12,2,'Ishi Q2?','A',100)`,
	} {
		if _, err := db.Exec(q); err != nil {
			return err
		}
	}
	return nil
}

// msisdn returns a fresh synthetic MSISDN whose last digit picks the fake
// provider's outcome (see cmd/yas-fakeprovider).
func msisdn(outcome int) string {
	return fmt.Sprintf("2557999%s%02d%d", runPrefix, caseSeq.Add(1)%100, outcome)
}

var reqSeq atomic.Int64

func reqID(tag string) string {
	return fmt.Sprintf("SYS%s-%s-%d-%d", runPrefix, tag, time.Now().UnixNano(), reqSeq.Add(1))
}

func post(t *testing.T, route, body string) (int, string) {
	t.Helper()
	req, _ := http.NewRequest(http.MethodPost, callbackURL+route, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	if token != "" {
		req.Header.Set("X-Callback-Token", token)
	}
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	raw, _ := io.ReadAll(resp.Body)
	return resp.StatusCode, string(raw)
}

func envelope(id, msisdn, kind string, extra map[string]string) string {
	data := fmt.Sprintf(`{"name":"Msisdn","value":%q},{"name":"Type","value":%q}`, msisdn, kind)
	for k, v := range extra {
		data += fmt.Sprintf(`,{"name":%q,"value":%q}`, k, v)
	}
	return fmt.Sprintf(`{"requestId":%q,"requestParam":{"data":[%s]}}`, id, data)
}

var routes = map[string]string{"soka": "/soka-trivia/notification", "ishikistaa": "/ishikistaa/notification"}

func mo(t *testing.T, product, msisdn, text string) {
	t.Helper()
	if code, body := post(t, routes[product], envelope(reqID("MO"), msisdn, "InteractiveResponse", map[string]string{"CustomerResponse": text})); code != http.StatusOK {
		t.Fatalf("MO %q: %d %s", text, code, body)
	}
}

func activate(t *testing.T, product, msisdn string, extra map[string]string) {
	t.Helper()
	fields := map[string]string{"SubscriptionStatus": "A"}
	for k, v := range extra {
		fields[k] = v
	}
	if code, body := post(t, routes[product], envelope(reqID("ACT"), msisdn, "Activation", fields)); code != http.StatusOK {
		t.Fatalf("activation: %d %s", code, body)
	}
}

type providerRecord struct {
	Kind, RequestID, MSISDN, Status string
	Fields                          map[string]string
}

func provider(t *testing.T, kind, msisdn string) []providerRecord {
	t.Helper()
	resp, err := http.Get(providerURL + "/requests?kind=" + kind + "&msisdn=" + msisdn)
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()
	var out []providerRecord
	if err := json.NewDecoder(resp.Body).Decode(&out); err != nil {
		t.Fatal(err)
	}
	return out
}

// eventually polls until ok returns true, failing with describe() on timeout.
func eventually(t *testing.T, what string, ok func() bool, describe func() string) {
	t.Helper()
	deadline := time.Now().Add(45 * time.Second)
	for time.Now().Before(deadline) {
		if ok() {
			return
		}
		time.Sleep(250 * time.Millisecond)
	}
	t.Fatalf("timed out waiting for %s: %s", what, describe())
}

// smsContaining waits for an SMS to msisdn whose content contains text.
func smsContaining(t *testing.T, msisdn, text string) providerRecord {
	t.Helper()
	var found providerRecord
	eventually(t, "SMS containing "+text, func() bool {
		for _, r := range provider(t, "sms", msisdn) {
			if r.Status == "0" && strings.Contains(r.Fields["Content"], text) {
				found = r
				return true
			}
		}
		return false
	}, func() string { return fmt.Sprintf("%+v", provider(t, "sms", msisdn)) })
	return found
}

func charges(t *testing.T, msisdn string) []providerRecord { return provider(t, "charge", msisdn) }

func waitCharges(t *testing.T, msisdn string, n int) []providerRecord {
	t.Helper()
	eventually(t, fmt.Sprintf("%d charges", n), func() bool { return len(charges(t, msisdn)) >= n },
		func() string { return fmt.Sprintf("%+v", charges(t, msisdn)) })
	return charges(t, msisdn)
}

func queryInt(t *testing.T, q string, args ...any) int {
	t.Helper()
	var n sql.NullInt64
	if err := db.QueryRow(q, args...).Scan(&n); err != nil {
		t.Fatal(q, err)
	}
	return int(n.Int64)
}

func points(t *testing.T, product, msisdn string) int {
	return queryInt(t, `SELECT COALESCE(SUM(pl.delta_points),0) FROM point_ledger pl JOIN subscribers s ON s.id=pl.subscriber_id
		JOIN products p ON p.id=pl.product_id WHERE s.msisdn=? AND p.code=?`, msisdn, product)
}

func state(t *testing.T, product, msisdn string) string {
	var st sql.NullString
	_ = db.QueryRow(`SELECT ps.state FROM product_subscriptions ps JOIN subscribers s ON s.id=ps.subscriber_id JOIN products p ON p.id=ps.product_id
		WHERE s.msisdn=? AND p.code=?`, msisdn, product).Scan(&st)
	return st.String
}

func waitState(t *testing.T, product, msisdn, want string) {
	t.Helper()
	eventually(t, "subscription "+want, func() bool { return state(t, product, msisdn) == want }, func() string { return state(t, product, msisdn) })
}

// subscribed activates a fresh Soka or Ishikistaa subscriber and waits for the welcome.
func subscribed(t *testing.T, product string, outcome int) string {
	t.Helper()
	m := msisdn(outcome)
	activate(t, product, m, nil)
	waitState(t, product, m, "ACTIVE")
	if outcome != 4 {
		smsContaining(t, m, "?.")
	}
	return m
}

func TestIngressRejectsUnauthenticated(t *testing.T) {
	body := envelope(reqID("ING"), msisdn(0), "InteractiveResponse", map[string]string{"CustomerResponse": "A"})
	for name, header := range map[string]string{"none": "", "wrong": strings.Repeat("x", 40)} {
		req, _ := http.NewRequest(http.MethodPost, callbackURL+"/soka-trivia/notification", strings.NewReader(body))
		if header != "" {
			req.Header.Set("X-Callback-Token", header)
		}
		resp, err := http.DefaultClient.Do(req)
		if err != nil {
			t.Fatal(err)
		}
		resp.Body.Close()
		if resp.StatusCode != http.StatusForbidden {
			t.Errorf("%s token: %d, want 403", name, resp.StatusCode)
		}
	}
	if code, _ := post(t, "/soka-trivia/notification", `{"requestId":"x"}`); code != http.StatusBadRequest {
		t.Errorf("malformed callback: %d, want 400", code)
	}
}

func TestDuplicateAndConflictingCallbacks(t *testing.T) {
	m := subscribed(t, "soka", 0)
	id := reqID("DUP")
	body := envelope(id, m, "InteractiveResponse", map[string]string{"CustomerResponse": "A"})
	for i := 0; i < 3; i++ {
		if code, _ := post(t, "/soka-trivia/notification", body); code != http.StatusOK {
			t.Fatalf("retry %d: %d", i, code)
		}
	}
	waitCharges(t, m, 1)
	time.Sleep(3 * time.Second)
	if n := len(charges(t, m)); n != 1 {
		t.Fatalf("a retried callback must charge once, got %d", n)
	}
	changed := envelope(id, m, "InteractiveResponse", map[string]string{"CustomerResponse": "B"})
	if code, _ := post(t, "/soka-trivia/notification", changed); code != http.StatusConflict {
		t.Fatalf("changed payload for the same request ID: %d, want 409", code)
	}
}

func TestSokaActivationWelcomeAndRenewal(t *testing.T) {
	m := msisdn(0)
	activate(t, "soka", m, nil)
	sms := smsContaining(t, m, "Una point 0. PAPO HAPO unabahatika na SOKA;")
	if sms.Fields["OfferCode"] != "017008600010" || !strings.Contains(sms.Fields["Content"], "Vigezo na masharti") {
		t.Fatalf("welcome %+v", sms)
	}
	eventually(t, "both renewal endpoints", func() bool {
		return len(provider(t, "renewal-manage", m)) == 1 && len(provider(t, "renewal-subs", m)) == 1
	}, func() string { return "renewal requests missing" })
	if r := provider(t, "renewal-manage", m)[0]; r.Fields["serviceID"] != "SOKA" || r.Fields["activityType"] != "SUBSCRIPTION" {
		t.Fatalf("renewal body %+v", r.Fields)
	}
}

func TestSokaPaidCorrectAnswer(t *testing.T) {
	m := subscribed(t, "soka", 0)
	mo(t, "soka", m, "a")
	c := waitCharges(t, m, 1)[0]
	if c.Fields["ChargeAmount"] != "99" || c.Fields["OfferCode"] != "017008600005" {
		t.Fatalf("answer charge %+v", c.Fields)
	}
	eventually(t, "answer points", func() bool { p := points(t, "soka", m); return p == 100 || p == 200 }, func() string { return fmt.Sprint(points(t, "soka", m)) })
	smsContaining(t, m, "Unapoint ")
}

func TestSokaLowBalanceFallbackCredits(t *testing.T) {
	m := subscribed(t, "soka", 1)
	mo(t, "soka", m, "A")
	c := waitCharges(t, m, 2)
	if c[0].Fields["ChargeAmount"] != "99" || c[1].Fields["ChargeAmount"] != "45" || !strings.HasPrefix(c[1].RequestID, "F") {
		t.Fatalf("fallback ladder %+v", c)
	}
	eventually(t, "credit after fallback", func() bool { return points(t, "soka", m) > 0 }, func() string { return "no points" })
}

func TestSokaLowBalanceExhausted(t *testing.T) {
	m := subscribed(t, "soka", 2)
	mo(t, "soka", m, "A")
	c := waitCharges(t, m, 3)
	if c[2].Fields["ChargeAmount"] != "30" || !strings.HasPrefix(c[2].RequestID, "FF") {
		t.Fatalf("third charge %+v", c[2])
	}
	smsContaining(t, m, "hauna salio la kutosha kucheza SOKA")
	if points(t, "soka", m) != 0 {
		t.Fatal("no points without a successful charge")
	}
}

func TestSokaBillingError(t *testing.T) {
	m := subscribed(t, "soka", 3)
	mo(t, "soka", m, "A")
	waitCharges(t, m, 1)
	smsContaining(t, m, "Tafadhali tuma jibu lako tena")
	time.Sleep(2 * time.Second)
	if n := len(charges(t, m)); n != 1 {
		t.Fatalf("a billing error must not fall back: %d charges", n)
	}
}

func TestSokaPointPurchase(t *testing.T) {
	m := subscribed(t, "soka", 0)
	mo(t, "soka", m, "p600")
	c := waitCharges(t, m, 1)[0]
	if c.Fields["ChargeAmount"] != "500" || !strings.HasPrefix(c.RequestID, "PU") {
		t.Fatalf("purchase charge %+v", c)
	}
	eventually(t, "600 purchase points", func() bool { return points(t, "soka", m) == 600 }, func() string { return fmt.Sprint(points(t, "soka", m)) })
	smsContaining(t, m, "Umefanikiwa kununua POINT 600")
	mo(t, "soka", m, "P750")
	smsContaining(t, m, "Kununua ALAMA tafadhali tuma")
	time.Sleep(2 * time.Second)
	if n := len(charges(t, m)); n != 1 {
		t.Fatalf("an unknown P-keyword must not charge: %d", n)
	}
}

func TestSokaKeywordReplies(t *testing.T) {
	m := subscribed(t, "soka", 0)
	mo(t, "soka", m, "points")
	smsContaining(t, m, "Point zako ni 0.")
	mo(t, "soka", m, "msaada")
	smsContaining(t, m, "Shinda hadi Tsh 100,000,000")
	mo(t, "soka", m, "ondoa")
	smsContaining(t, m, "tuma ONDOA SOKA kwenda 15670")
	if len(charges(t, m)) != 0 {
		t.Fatal("keywords must not charge")
	}
}

func TestSokaUnsubscribeAndReactivate(t *testing.T) {
	m := subscribed(t, "soka", 0)
	mo(t, "soka", m, "STOP")
	waitState(t, "soka", m, "INACTIVE")
	eventually(t, "unsubscription reported", func() bool {
		for _, r := range provider(t, "renewal-manage", m) {
			if r.Fields["activityType"] == "UNSUBSCRIPTION" {
				return true
			}
		}
		return false
	}, func() string { return fmt.Sprintf("%+v", provider(t, "renewal-manage", m)) })
	mo(t, "soka", m, "hello")
	smsContaining(t, m, "Tuma neno SOKA rudi mchezoni")
	mo(t, "soka", m, "soka")
	waitState(t, "soka", m, "ACTIVE")
	if len(charges(t, m)) != 0 {
		t.Fatal("an inactive subscriber must never be charged")
	}
}

func TestSokaMpushActivation(t *testing.T) {
	m := msisdn(0)
	code, body := post(t, "/soka-mpush/notification", fmt.Sprintf(`{"msisdn":%q,"channel":"USSD","ussd_data":"*150*00#"}`, m))
	if code != http.StatusOK || !strings.Contains(body, `"statusCode":"0000"`) {
		t.Fatalf("mpush reply %d %s", code, body)
	}
	eventually(t, "ACTIVATE request", func() bool { return len(provider(t, "activate", m)) == 1 }, func() string { return "no activation" })
	if a := provider(t, "activate", m)[0]; a.Fields["OfferCode"] != "017008600010" || !strings.HasPrefix(a.RequestID, "MP") {
		t.Fatalf("activation %+v", a)
	}
	// The fake provider then sends the Activation callback, which subscribes and confirms.
	waitState(t, "soka", m, "ACTIVE")
	eventually(t, "confirmed attempt", func() bool {
		return queryInt(t, `SELECT COUNT(*) FROM activation_attempts a JOIN subscribers s ON s.id=a.subscriber_id WHERE s.msisdn=? AND a.state='CONFIRMED'`, m) == 1
	}, func() string { return "attempt not confirmed" })
}

func TestSokaSMSResendAfterRefusal(t *testing.T) {
	m := msisdn(4)
	activate(t, "soka", m, nil)
	eventually(t, "refused then resent welcome", func() bool {
		recs := provider(t, "sms", m)
		return len(recs) >= 2 && recs[0].Status == "1" && strings.HasPrefix(recs[1].RequestID, "IR") && recs[1].Status == "0"
	}, func() string { return fmt.Sprintf("%+v", provider(t, "sms", m)) })
}

func TestSokaPredictAndWin(t *testing.T) {
	now := time.Now().UTC()
	loc, _ := time.LoadLocation("Africa/Dar_es_Salaam")
	today := now.In(loc).Format("2006-01-02")
	var campaign int64
	res, err := db.Exec(`INSERT INTO predict_campaigns(product_id,name,status,start_date,end_date) VALUES (1,?,'ACTIVE',?,?)`, "SYS"+runPrefix, today, today)
	if err != nil {
		t.Fatal(err)
	}
	campaign, _ = res.LastInsertId()
	if _, err := db.Exec(`DELETE FROM predict_match_days WHERE match_date=? AND status='PUBLISHED' AND campaign_id<>?`, today, campaign); err != nil {
		t.Logf("older match days left in place: %v", err)
	}
	res, err = db.Exec(`INSERT INTO predict_match_days(campaign_id,match_date,status) VALUES (?,?,'PUBLISHED')`, campaign, today)
	if err != nil {
		t.Fatal(err)
	}
	day, _ := res.LastInsertId()
	if _, err := db.Exec(`INSERT INTO predict_fixtures(match_day_id,slot,display_text,kickoff_at,status) VALUES (?,1,'Simba v Yanga',?,'SCHEDULED')`, day, now.Add(3*time.Hour)); err != nil {
		t.Fatal(err)
	}
	m := subscribed(t, "soka", 0)
	mo(t, "soka", m, "PREDICT")
	smsContaining(t, m, "1. Simba v Yanga")
	mo(t, "soka", m, "M1")
	smsContaining(t, m, "Asante kwa kuchagua mechi")
	mo(t, "soka", m, "M2-1")
	c := waitCharges(t, m, 1)[0]
	if !strings.HasPrefix(c.RequestID, "PD") || c.Fields["ChargeAmount"] != "99" {
		t.Fatalf("prediction charge %+v", c)
	}
	smsContaining(t, m, "Ubashiri wako umepokelewa")
	if points(t, "soka", m) != 0 {
		t.Fatal("predictions earn no points")
	}
}

func TestSokaOpenQuestionSchedule(t *testing.T) {
	m := subscribed(t, "soka", 0)
	content := "SYS" + runPrefix + " open %s;"
	if _, err := db.Exec(`INSERT INTO question_schedules(product_id,scheduled_for,state,content,audience) VALUES (1,UTC_TIMESTAMP(6),'PENDING',?,'ALL')`, content); err != nil {
		t.Fatal(err)
	}
	smsContaining(t, m, "SYS"+runPrefix+" open 0;")
}

func TestSokaBoomFreeAnswer(t *testing.T) {
	m := msisdn(0)
	activate(t, "soka", m, map[string]string{"CustomerType": "BOOM_CUSTOMER"})
	smsContaining(t, m, "Umezawadiwa nafasi 1 ya kujibu swali la SOKA buree")
	mo(t, "soka", m, "A")
	eventually(t, "free answer credit", func() bool { return points(t, "soka", m) > 0 }, func() string { return "no points" })
	if len(charges(t, m)) != 0 {
		t.Fatal("a BOOM free answer must not be charged")
	}
	if cmd := os.Getenv("SYSTEM_BOOM_AWARD_CMD"); cmd != "" {
		round := "SYS" + runPrefix + fmt.Sprint(time.Now().Unix())
		out, err := exec.Command("sh", "-c", fmt.Sprintf(cmd, round)).CombinedOutput()
		if err != nil {
			t.Fatalf("award round: %v %s", err, out)
		}
		if queryInt(t, `SELECT ps.boom_credits FROM product_subscriptions ps JOIN subscribers s ON s.id=ps.subscriber_id WHERE s.msisdn=? AND ps.product_id=1`, m) != 1 {
			t.Fatal("the award round must restore the BOOM credit")
		}
	}
}

func TestIshikistaaJourney(t *testing.T) {
	m := msisdn(0)
	activate(t, "ishikistaa", m, nil)
	sms := smsContaining(t, m, "Jibu swali ushinde zaidi ya 12,000,000")
	if sms.Fields["OfferCode"] != "017008600009" {
		t.Fatalf("Ishikistaa SMS offer %s", sms.Fields["OfferCode"])
	}
	mo(t, "ishikistaa", m, "A")
	c := waitCharges(t, m, 1)[0]
	if c.Fields["OfferCode"] != "017008600007" || c.Fields["ChargeAmount"] != "99" {
		t.Fatalf("Ishikistaa charge %+v", c.Fields)
	}
	smsContaining(t, m, "Una pointi ")
	mo(t, "ishikistaa", m, "stop")
	smsContaining(t, m, "KUJIUNGA tena tuma STAA")
	waitState(t, "ishikistaa", m, "INACTIVE")
	if r := provider(t, "renewal-manage", m); len(r) == 0 || r[0].Fields["serviceID"] != "ISHIKISTAA" {
		t.Fatalf("Ishikistaa renewal %+v", r)
	}
}

func TestIshikistaaFallbackIsCredited(t *testing.T) {
	m := msisdn(1)
	activate(t, "ishikistaa", m, nil)
	waitState(t, "ishikistaa", m, "ACTIVE")
	mo(t, "ishikistaa", m, "A")
	c := waitCharges(t, m, 2)
	if c[1].Fields["ChargeAmount"] != "45" || c[1].Fields["OfferCode"] != "017008600007" {
		t.Fatalf("Ishikistaa fallback %+v", c[1].Fields)
	}
	// PHP never credits this (LOW_BAANCE_FAILED); Go must.
	eventually(t, "Ishikistaa fallback credit", func() bool { return points(t, "ishikistaa", m) > 0 }, func() string { return "no points" })
}

func TestMegaPromoAndPushCallbacksStored(t *testing.T) {
	ref := reqID("GTL")
	code, body := post(t, "/notification/megapromo/tigopesapush/collection", fmt.Sprintf(`{"ReferenceID":%q,"Status":true,"Description":"ok"}`, ref))
	if code != http.StatusOK || !strings.Contains(body, "BILLER-30-0000-S") || !strings.Contains(body, ref) {
		t.Fatalf("push reply %d %s", code, body)
	}
	if code, _ := post(t, "/notification/megapromo", envelope(reqID("MP"), msisdn(0), "InteractiveResponse", map[string]string{"CustomerResponse": "zali"})); code != http.StatusOK {
		t.Fatalf("MegaPromo MO %d", code)
	}
	eventually(t, "push stored and published", func() bool {
		return queryInt(t, `SELECT COUNT(*) FROM callback_events ce JOIN callback_outbox co ON co.event_id=ce.id
			WHERE ce.external_request_id=? AND co.published_at IS NOT NULL`, ref) == 1
	}, func() string { return "not published" })
}

func TestOperationalEndpoints(t *testing.T) {
	if opsURL == "" {
		t.Skip("SYSTEM_OPS_URL not set")
	}
	for port, metric := range map[int]string{
		19091: "yas_callback_requests_total", 19092: "yas_relay_published_total", 19093: "yas_consumer_connected",
		19095: "yas_charge_intents", 19096: "yas_outbound_messages", 19097: "yas_renewal_requests",
		19098: "yas_question_schedules", 19099: "yas_activation_attempts", 19100: "yas_consumer_connected",
	} {
		for _, path := range []string{"/healthz", "/readyz"} {
			resp, err := http.Get(fmt.Sprintf("%s:%d%s", opsURL, port, path))
			if err != nil || resp.StatusCode != http.StatusNoContent {
				t.Errorf("%d%s: %v %v", port, path, err, resp)
			}
			if resp != nil {
				resp.Body.Close()
			}
		}
		resp, err := http.Get(fmt.Sprintf("%s:%d/metrics", opsURL, port))
		if err != nil {
			t.Fatal(err)
		}
		raw, _ := io.ReadAll(resp.Body)
		resp.Body.Close()
		if !bytes.Contains(raw, []byte(metric)) {
			t.Errorf("port %d metrics lack %s", port, metric)
		}
	}
}

func TestNothingDeadLettered(t *testing.T) {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	var n int
	if err := db.QueryRowContext(ctx, `SELECT COUNT(*) FROM callback_outbox WHERE dead_lettered_at IS NOT NULL`).Scan(&n); err != nil || n != 0 {
		t.Fatalf("outbox dead letters %d err=%v", n, err)
	}
	if n := queryInt(t, `SELECT COUNT(*) FROM charge_intents WHERE state='NEEDS_REVIEW'`); n != 0 {
		t.Fatalf("charges needing review: %d", n)
	}
}
