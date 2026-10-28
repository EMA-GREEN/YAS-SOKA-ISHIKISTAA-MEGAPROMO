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

func TestAnswerJourneyIntegration(t *testing.T) {
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
	// 08:00 UTC is 11:00 in Dar es Salaam (not golden); 17:00 UTC is 20:00 (golden).
	normal := time.Date(2026, 10, 28, 8, 0, 0, 0, time.UTC)
	golden := time.Date(2026, 10, 28, 17, 0, 0, 0, time.UTC)
	clock := normal
	now := func() time.Time { return clock }
	exec(`INSERT INTO products(id,code,display_name) VALUES (1,'soka','Soka')`)
	exec(`INSERT INTO subscribers(id,msisdn) VALUES (1,'255700000001')`)
	exec(`INSERT INTO questions(id,product_id,question_text,answer_key,points) VALUES (11,1,'Q-ONE','A',100),(12,1,'Q-TWO','B',100)`)
	exec(`INSERT INTO product_subscriptions(product_id,subscriber_id,state,billing_segment,started_at,current_question_id)
		VALUES (1,1,'ACTIVE','NORMAL',UTC_TIMESTAMP(6),11)`)
	exec(`INSERT INTO answer_level_bonuses(product_id,bonus_date,level,bonus_points,reward_message) VALUES (1,'2026-10-28',1,30,'Hongera LEVEL 1!')`)

	mo := Processor{DB: store.DB, Now: now}
	pay := sokapoints.Processor{DB: store.DB, Now: now}
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
	sendMO := func(request, text, reason string) int64 {
		t.Helper()
		return save(fmt.Sprintf(`{"requestId":%q,"requestParam":{"data":[{"name":"Msisdn","value":"255700000001"},{"name":"Type","value":"InteractiveResponse"},{"name":"CustomerResponse","value":%q},{"name":"Reason","value":%q},{"name":"TransactionId","value":"T-%s"}]}}`, request, text, reason, request))
	}
	payment := func(request, charged, reason, billing, system string) int64 {
		t.Helper()
		return save(fmt.Sprintf(`{"requestId":%q,"requestParam":{"data":[{"name":"Msisdn","value":"255700000001"},{"name":"Type","value":"Payment"},{"name":"ChargeAmount","value":%q},{"name":"Reason","value":%q},{"name":"BillingId","value":%q},{"name":"billingSystem","value":%q}]}}`, request, charged, reason, billing, system))
	}
	attemptFor := func(answerID int64) (id int64, request string, amount int64, state, offer string) {
		t.Helper()
		if err := store.DB.QueryRowContext(ctx, `SELECT pa.id, pa.external_request_id, pa.amount_minor, pa.state, ci.offer_code
			FROM answers a JOIN payment_attempts pa ON pa.id=a.payment_attempt_id JOIN charge_intents ci ON ci.payment_attempt_id=pa.id WHERE a.id=?`, answerID).
			Scan(&id, &request, &amount, &state, &offer); err != nil {
			t.Fatal(err)
		}
		return
	}
	mustProcess := func(name string, err error) {
		t.Helper()
		if err != nil {
			t.Fatalf("%s: %v", name, err)
		}
	}

	// 1. A correct answer is scored now, with the level-1 bonus, and charged 99.
	first := sendMO("ANS-1", " a ", "")
	mustProcess("answer", mo.Process(ctx, first))
	mustProcess("answer replay", mo.Process(ctx, first))
	var answerID, points int64
	var result string
	if err := store.DB.QueryRowContext(ctx, `SELECT id, result, points_on_success FROM answers WHERE question_id=11`).Scan(&answerID, &result, &points); err != nil {
		t.Fatal(err)
	}
	if result != "CORRECT" || points != 130 || count(`SELECT COUNT(*) FROM answers`) != 1 {
		t.Fatalf("answer result=%s points=%d", result, points)
	}
	_, request, amount, state, offer := attemptFor(answerID)
	if request != fmt.Sprintf("%d255700000001", first) || amount != 99 || state != "PENDING" || offer != sokagame.NormalOffer {
		t.Fatalf("answer charge request=%s amount=%d state=%s offer=%s", request, amount, state, offer)
	}
	if count(`SELECT COUNT(*) FROM point_ledger`) != 0 {
		t.Fatal("points must wait for the charge")
	}

	// 2. Low balance steps down 99 → 45 → 30 on the normal offer, with no message.
	low1 := payment(request, "99", "Low Balance", "", "")
	mustProcess("low 99", pay.Process(ctx, low1))
	mustProcess("low 99 replay", pay.Process(ctx, low1))
	fallbackID, request45, amount, state, offer := attemptFor(answerID)
	if amount != 45 || state != "PENDING" || request45 != fmt.Sprintf("F%d255700000001", low1) || offer != sokagame.NormalOffer {
		t.Fatalf("first fallback amount=%d state=%s request=%s", amount, state, request45)
	}
	if count(`SELECT COUNT(*) FROM payment_attempts WHERE id=? AND fallback_of_attempt_id IS NOT NULL`, fallbackID) != 1 {
		t.Fatal("fallback must point at the attempt it replaces")
	}
	low2 := payment(request45, "45", "Insufficient balance", "", "")
	mustProcess("low 45", pay.Process(ctx, low2))
	_, request30, amount, _, _ := attemptFor(answerID)
	if amount != 30 || !strings.HasPrefix(request30, "FF") {
		t.Fatalf("second fallback amount=%d request=%s", amount, request30)
	}
	if count(`SELECT COUNT(*) FROM outbound_messages`) != 0 {
		t.Fatal("fallback charges send no message")
	}

	// 3. The 30 TZS charge succeeds: points credited once, level raised, next
	// question and the level reward sent.
	paid := payment(request30, "30", "", "B-30", "")
	mustProcess("success", pay.Process(ctx, paid))
	mustProcess("success replay", pay.Process(ctx, paid))
	if count(`SELECT COALESCE(SUM(delta_points),0) FROM point_ledger WHERE answer_id=? AND reason='ANSWER'`, answerID) != 130 ||
		count(`SELECT COUNT(*) FROM point_ledger`) != 1 {
		t.Fatal("answer points not credited exactly once")
	}
	var level, current int64
	if err := store.DB.QueryRowContext(ctx, `SELECT answer_level, current_question_id FROM product_subscriptions`).Scan(&level, &current); err != nil {
		t.Fatal(err)
	}
	if level != 1 || (current != 11 && current != 12) {
		t.Fatalf("level=%d current=%d", level, current)
	}
	var questionText, body string
	if err := store.DB.QueryRowContext(ctx, `SELECT question_text FROM questions WHERE id=?`, current).Scan(&questionText); err != nil {
		t.Fatal(err)
	}
	if err := store.DB.QueryRowContext(ctx, `SELECT body_text FROM outbound_messages WHERE message_kind='QUESTION'`).Scan(&body); err != nil || body != "Unapoint 130!"+questionText+"." {
		t.Fatalf("question message %q err=%v", body, err)
	}
	if count(`SELECT COUNT(*) FROM outbound_messages WHERE message_kind='REWARD_MSG' AND body_text='Hongera LEVEL 1!'`) != 1 {
		t.Fatal("level reward message missing")
	}

	// 4. A wrong answer in golden hour earns 50 once charged.
	exec(`UPDATE product_subscriptions SET current_question_id=11`)
	clock = golden
	wrong := sendMO("ANS-2", "zzz", "")
	mustProcess("wrong answer", mo.Process(ctx, wrong))
	var wrongAnswer int64
	if err := store.DB.QueryRowContext(ctx, `SELECT id, result, points_on_success FROM answers WHERE answer_text='zzz'`).Scan(&wrongAnswer, &result, &points); err != nil {
		t.Fatal(err)
	}
	if result != "WRONG" || points != 50 {
		t.Fatalf("golden wrong result=%s points=%d", result, points)
	}
	_, request, _, _, _ = attemptFor(wrongAnswer)
	mustProcess("wrong success", pay.Process(ctx, payment(request, "99", "", "B-99", "")))
	if count(`SELECT COALESCE(SUM(delta_points),0) FROM point_ledger`) != 180 {
		t.Fatal("balance after second answer")
	}
	clock = normal

	// 5. A billing error asks the customer to answer again and earns nothing.
	errAnswer := sendMO("ANS-3", "A", "")
	mustProcess("third answer", mo.Process(ctx, errAnswer))
	var thirdAnswer int64
	if err := store.DB.QueryRowContext(ctx, `SELECT a.id FROM answers a JOIN interactions i ON i.id=a.interaction_id WHERE i.external_request_id='ANS-3'`).Scan(&thirdAnswer); err != nil {
		t.Fatal(err)
	}
	failedID, request, _, _, _ := attemptFor(thirdAnswer)
	mustProcess("billing error", pay.Process(ctx, payment(request, "99", "Subscriber barred", "", "")))
	if count(`SELECT COUNT(*) FROM payment_attempts WHERE id=? AND state='BILLING_ERROR'`, failedID) != 1 ||
		count(`SELECT COUNT(*) FROM outbound_messages WHERE message_kind='ERROR' AND body_text=?`, sokagame.ErrorMsg) != 1 ||
		count(`SELECT COUNT(*) FROM payment_attempts WHERE fallback_of_attempt_id=?`, failedID) != 0 {
		t.Fatal("billing error outcome")
	}
	// A second, different callback for the settled charge is refused at intake.
	late, err := callbacks.ParseJSON("soka", []byte(fmt.Sprintf(`{"requestId":%q,"requestParam":{"data":[{"name":"Msisdn","value":"255700000001"},{"name":"Type","value":"Payment"},{"name":"ChargeAmount","value":"99"},{"name":"BillingId","value":"B-LATE"}]}}`, request)))
	if err != nil {
		t.Fatal(err)
	}
	if _, err := store.Save(ctx, late); !errors.Is(err, callbacks.ErrConflict) {
		t.Fatalf("late callback for settled charge err=%v", err)
	}

	// 6. A wallet payment gets no fallback, only the low-balance message.
	wallet := sendMO("ANS-4", "B", "")
	mustProcess("wallet answer", mo.Process(ctx, wallet))
	var walletAnswer int64
	if err := store.DB.QueryRowContext(ctx, `SELECT a.id FROM answers a JOIN interactions i ON i.id=a.interaction_id WHERE i.external_request_id='ANS-4'`).Scan(&walletAnswer); err != nil {
		t.Fatal(err)
	}
	walletAttempt, request, _, _, _ := attemptFor(walletAnswer)
	mustProcess("wallet low", pay.Process(ctx, payment(request, "99", "Low balance", "", "WALLET_ACCOUNT")))
	if count(`SELECT COUNT(*) FROM payment_attempts WHERE fallback_of_attempt_id=?`, walletAttempt) != 0 ||
		count(`SELECT COUNT(*) FROM outbound_messages WHERE message_kind='LOW_BALANCE' AND body_text=?`, sokagame.LowBalance) != 1 {
		t.Fatal("wallet low balance outcome")
	}

	// 7. Keyword replies are messages, never charges; unported flows are left alone.
	charges := count(`SELECT COUNT(*) FROM charge_intents`)
	for request, want := range map[string]string{"KW-POINTS": "points", "KW-HELP": "msaada", "KW-ONDOA": "ondoa"} {
		mustProcess(request, mo.Process(ctx, sendMO(request, want, "")))
	}
	if err := store.DB.QueryRowContext(ctx, `SELECT body_text FROM outbound_messages WHERE message_kind='POINT_MSG'`).Scan(&body); err != nil || !strings.HasPrefix(body, "Point zako ni 180.") {
		t.Fatalf("points reply %q err=%v", body, err)
	}
	if count(`SELECT COUNT(*) FROM outbound_messages WHERE message_kind IN ('HELP_MESSAGE','ONDOA_UNSUB_SMS')`) != 2 {
		t.Fatal("help and ondoa replies")
	}
	// Predict traffic gets the predict hint, never an answer charge.
	mustProcess("predict", mo.Process(ctx, sendMO("SKIP-PREDICT", "M2-1", "")))
	if count(`SELECT COUNT(*) FROM outbound_messages WHERE message_kind='PREDICT_HINT'`) != 1 {
		t.Fatal("predict traffic must get the predict hint")
	}
	// The provider's no-open-question reason gets the open question, not a charge.
	mustProcess("open question", mo.Process(ctx, sendMO("OPEN-Q", "A", "No open question for the subscriber")))
	if err := store.DB.QueryRowContext(ctx, `SELECT body_text FROM outbound_messages WHERE message_kind='OPEN_QUESTION'`).Scan(&body); err != nil ||
		!strings.HasPrefix(body, "Hongera sana umejikusanyia alama 180 hadi sasa.") {
		t.Fatalf("open question %q err=%v", body, err)
	}
	if count(`SELECT COUNT(*) FROM charge_intents`) != charges {
		t.Fatal("keywords or skipped MOs created a charge")
	}

	// 8. Answers need a verified billing segment and a current question.
	exec(`UPDATE product_subscriptions SET billing_segment='UNVERIFIED'`)
	if err := mo.Process(ctx, sendMO("ANS-UNVERIFIED", "A", "")); !errors.Is(err, ErrNeedsReview) {
		t.Fatalf("unverified answer err=%v", err)
	}
	exec(`UPDATE product_subscriptions SET billing_segment='BOOM', current_question_id=NULL`)
	if err := mo.Process(ctx, sendMO("ANS-NOQ", "A", "")); !errors.Is(err, ErrNeedsReview) {
		t.Fatalf("no current question err=%v", err)
	}
	// BOOM customers' first answer charge uses the BOOM offer.
	exec(`UPDATE product_subscriptions SET current_question_id=12`)
	boom := sendMO("ANS-BOOM", "b", "")
	mustProcess("boom answer", mo.Process(ctx, boom))
	var boomAnswer int64
	if err := store.DB.QueryRowContext(ctx, `SELECT a.id FROM answers a JOIN interactions i ON i.id=a.interaction_id WHERE i.external_request_id='ANS-BOOM'`).Scan(&boomAnswer); err != nil {
		t.Fatal(err)
	}
	if _, _, _, _, offer = attemptFor(boomAnswer); offer != "017008600272" {
		t.Fatalf("BOOM answer offer %s", offer)
	}
}
