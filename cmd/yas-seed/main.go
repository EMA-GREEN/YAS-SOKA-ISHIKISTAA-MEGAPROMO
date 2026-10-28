// Command yas-seed fills a test stack with a demo data set: reference data
// (products, a question bank, a Predict-and-Win match day) written directly,
// and subscriber history produced by posting real callbacks through the
// running services, so every answer, payment, point and SMS row is one the
// Go code wrote itself.
//
// Every subscriber is synthetic (2557997…; the system tests use 2557999 and the
// load generator 2557998). The last digit picks the fake provider's outcome.
// It refuses a non-loopback target unless -allow-remote is set, and any
// database not named yas_test_*.
package main

import (
	"database/sql"
	"flag"
	"fmt"
	"io"
	"math/rand"
	"net"
	"net/http"
	"net/url"
	"os"
	"strings"
	"time"

	driver "github.com/go-sql-driver/mysql"
	mysqlstore "yas-modernization/internal/store/mysql"
)

const prefix = "2557997"

type step struct {
	kind string // "activate" or "mo"
	text string // MO text
	boom bool   // activate as a BOOM customer
}

type persona struct {
	msisdn, product, story string
	steps                  []step
}

func act() step           { return step{kind: "activate"} }
func mo(text string) step { return step{kind: "mo", text: text} }
func answers(n int) []step { // a mix of right and wrong answers
	out := make([]step, n)
	for i := range out {
		out[i] = mo([]string{"A", "B", "A", "C", "A"}[i%5])
	}
	return out
}
func with(first step, rest ...[]step) []step {
	out := []step{first}
	for _, r := range rest {
		out = append(out, r...)
	}
	return out
}

var cast = []persona{
	{prefix + "00010", "soka", "regular player: five answers, some right, some wrong", with(act(), answers(5))},
	{prefix + "00020", "soka", "bought 600 points with P600, then answered twice", with(act(), []step{mo("p600")}, answers(2))},
	{prefix + "00031", "soka", "low balance at 99 TZS, charged 45 instead (fallback ladder)", with(act(), answers(2))},
	{prefix + "00042", "soka", "low balance at every tier: answers never charged", with(act(), answers(2))},
	{prefix + "00053", "soka", "provider billing error on every charge", with(act(), answers(1))},
	{prefix + "00060", "soka", "answered, then sent STOP: now INACTIVE", with(act(), answers(2), []step{mo("STOP")})},
	{prefix + "00070", "soka", "BOOM customer: free answer credit, no charge", with(step{kind: "activate", boom: true}, answers(1))},
	{prefix + "00084", "soka", "first SMS refused by the provider, resent once", with(act(), answers(1))},
	{prefix + "00090", "soka", "Predict-and-Win: picked match 1 and predicted 2-1", with(act(), []step{mo("PREDICT"), mo("M1"), mo("M2-1")})},
	{prefix + "00100", "soka", "keyword replies: POINTS, MSAADA, and an unknown P-keyword", with(act(), []step{mo("points"), mo("msaada"), mo("P750")})},
	{prefix + "00110", "ishikistaa", "Ishikistaa player: four answers", with(act(), answers(4))},
	{prefix + "00121", "ishikistaa", "Ishikistaa low balance, charged on the fallback tier", with(act(), answers(2))},
	{prefix + "00130", "ishikistaa", "Ishikistaa player who sent STOP", with(act(), answers(1), []step{mo("stop")})},
	{prefix + "00140", "soka", "never subscribed: for a live demo, activate it, then answer", nil},
	{prefix + "00150", "ishikistaa", "never subscribed: for a live demo, activate it, then answer", nil},
}

var routes = map[string]string{"soka": "/soka-trivia/notification", "ishikistaa": "/ishikistaa/notification"}

func main() {
	target := flag.String("target", "http://127.0.0.1:18080", "callback server base URL")
	token := flag.String("token", "", "X-Callback-Token, if the ingress policy needs one")
	crowd := flag.Int("crowd", 40, "extra active Soka players with random answers, for volume")
	allowRemote := flag.Bool("allow-remote", false, "allow a non-loopback target")
	flag.Parse()

	u, err := url.Parse(*target)
	if err != nil || u.Host == "" {
		fail("invalid -target")
	}
	if ip := net.ParseIP(u.Hostname()); !*allowRemote && u.Hostname() != "localhost" && (ip == nil || !ip.IsLoopback()) {
		fail("refusing non-loopback target without -allow-remote")
	}
	if *crowd < 0 || *crowd > 999 {
		fail("-crowd must be 0-999")
	}
	db := openTestDB()
	defer db.Close()
	var exists int
	_ = db.QueryRow(`SELECT COUNT(*) FROM subscribers WHERE msisdn=?`, cast[0].msisdn).Scan(&exists)
	if exists > 0 {
		fmt.Println("demo data is already present; tear the stack down (or reset the database) to seed again")
		return
	}
	if err := reference(db); err != nil {
		fail("reference data: " + err.Error())
	}
	fmt.Println("reference data: 3 products, 12 questions, today's Predict-and-Win match day")

	s := &seeder{db: db, base: strings.TrimRight(*target, "/"), token: *token, run: "DEMO" + time.Now().UTC().Format("150405")}
	for _, p := range cast {
		if len(p.steps) == 0 {
			fmt.Printf("  %s  %-10s %s\n", p.msisdn, p.product, p.story)
			continue
		}
		if err := s.play(p); err != nil {
			fail(p.msisdn + ": " + err.Error())
		}
		fmt.Printf("  %s  %-10s %s\n", p.msisdn, p.product, p.story)
	}
	rnd := rand.New(rand.NewSource(7))
	for i := 0; i < *crowd; i++ {
		p := persona{msisdn: fmt.Sprintf("%s1%03d0", prefix, i), product: "soka", steps: []step{act()}}
		for j := rnd.Intn(4); j > 0; j-- {
			p.steps = append(p.steps, mo([]string{"A", "B", "C"}[rnd.Intn(3)]))
		}
		if err := s.play(p); err != nil {
			fail(p.msisdn + ": " + err.Error())
		}
	}
	if *crowd > 0 {
		fmt.Printf("  %s…  soka       %d more active players with 0-3 answers each\n", prefix+"1", *crowd)
	}
	summary(db)
}

type seeder struct {
	db               *sql.DB
	base, token, run string
	seq              int
}

// play posts each step and waits for the services to finish with it, so the
// next step sees the state the previous one left.
func (s *seeder) play(p persona) error {
	for _, st := range p.steps {
		s.seq++
		id := fmt.Sprintf("%s-%d", s.run, s.seq)
		fields := map[string]string{}
		kind := "InteractiveResponse"
		if st.kind == "activate" {
			kind = "Activation"
			fields["SubscriptionStatus"] = "A"
			if st.boom {
				fields["CustomerType"] = "BOOM_CUSTOMER"
			}
		} else {
			fields["CustomerResponse"] = st.text
		}
		before := s.messages(p)
		if err := s.post(routes[p.product], envelope(id, p.msisdn, kind, fields)); err != nil {
			return err
		}
		if err := s.processed(id); err != nil {
			return err
		}
		// Charged answers finish after the provider's payment callback; wait
		// for the reply SMS, but carry on if this step sends none.
		deadline := time.Now().Add(8 * time.Second)
		for s.messages(p) <= before && time.Now().Before(deadline) {
			time.Sleep(150 * time.Millisecond)
		}
	}
	return nil
}

func (s *seeder) post(route, body string) error {
	req, _ := http.NewRequest(http.MethodPost, s.base+route, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	if s.token != "" {
		req.Header.Set("X-Callback-Token", s.token)
	}
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 200))
		return fmt.Errorf("callback returned %d: %s", resp.StatusCode, b)
	}
	return nil
}

func (s *seeder) processed(id string) error {
	deadline := time.Now().Add(30 * time.Second)
	for time.Now().Before(deadline) {
		var n int
		if err := s.db.QueryRow(`SELECT COUNT(*) FROM processed_events pe JOIN callback_events ce ON ce.id=pe.callback_event_id
			WHERE ce.external_request_id=?`, id).Scan(&n); err != nil {
			return err
		}
		if n > 0 {
			return nil
		}
		time.Sleep(150 * time.Millisecond)
	}
	return fmt.Errorf("callback %s was not processed within 30s; are the workers running?", id)
}

func (s *seeder) messages(p persona) int {
	var n int
	_ = s.db.QueryRow(`SELECT COUNT(*) FROM outbound_messages om JOIN subscribers su ON su.id=om.subscriber_id
		JOIN products pr ON pr.id=om.product_id WHERE su.msisdn=? AND pr.code=?`, p.msisdn, p.product).Scan(&n)
	return n
}

func envelope(id, msisdn, kind string, extra map[string]string) string {
	data := fmt.Sprintf(`{"name":"Msisdn","value":%q},{"name":"Type","value":%q}`, msisdn, kind)
	for k, v := range extra {
		data += fmt.Sprintf(`,{"name":%q,"value":%q}`, k, v)
	}
	return fmt.Sprintf(`{"requestId":%q,"requestParam":{"data":[%s]}}`, id, data)
}

// reference writes the products, a small question bank with mixed answer keys,
// and a published Predict-and-Win match day for today in Tanzanian time.
func reference(db *sql.DB) error {
	stmts := []string{
		`INSERT IGNORE INTO products(id,code,display_name) VALUES (1,'soka','Soka'),(2,'ishikistaa','Ishikistaa'),(3,'megapromo','MegaPromo')`,
		`INSERT IGNORE INTO questions(id,product_id,question_text,answer_key,points) VALUES
			(101,1,'Timu gani ilishinda Kombe la Dunia 2022? A. Argentina B. Ufaransa C. Brazil','A',100),
			(102,1,'Uwanja wa Benjamin Mkapa uko mji gani? A. Dar es Salaam B. Arusha C. Mwanza','A',100),
			(103,1,'Mchezaji mmoja wa mpira wa miguu uwanjani ni wa timu ngapi? A. Moja B. Mbili C. Tatu','A',100),
			(104,1,'Mechi ya mpira wa miguu ina dakika ngapi za kawaida? A. 80 B. 90 C. 100','B',100),
			(105,1,'Kadi nyekundu inamaanisha nini? A. Onyo B. Kutolewa nje C. Penalti','B',100),
			(106,1,'Timu ina wachezaji wangapi uwanjani? A. 9 B. 10 C. 11','C',100),
			(107,1,'Kombe la AFCON linashindaniwa na bara gani? A. Afrika B. Ulaya C. Asia','A',100),
			(108,1,'Golikipa anaweza kushika mpira kwa mikono ndani ya eneo gani? A. Lake B. Lolote C. Katikati','A',100),
			(201,2,'Mji mkuu wa Tanzania ni upi? A. Dodoma B. Dar es Salaam C. Arusha','A',100),
			(202,2,'Mlima mrefu zaidi Afrika ni upi? A. Meru B. Kilimanjaro C. Kenya','B',100),
			(203,2,'Ziwa kubwa zaidi Afrika ni lipi? A. Victoria B. Tanganyika C. Nyasa','A',100),
			(204,2,'Tanzania ilipata uhuru mwaka gani? A. 1961 B. 1964 C. 1970','A',100)`,
	}
	for _, q := range stmts {
		if _, err := db.Exec(q); err != nil {
			return err
		}
	}
	loc, err := time.LoadLocation("Africa/Dar_es_Salaam")
	if err != nil {
		loc = time.FixedZone("EAT", 3*3600)
	}
	now := time.Now().UTC()
	today := now.In(loc).Format("2006-01-02")
	var open int
	if err := db.QueryRow(`SELECT COUNT(*) FROM predict_match_days WHERE match_date=? AND status='PUBLISHED'`, today).Scan(&open); err != nil || open > 0 {
		return err
	}
	res, err := db.Exec(`INSERT INTO predict_campaigns(product_id,name,status,start_date,end_date) VALUES (1,'Demo campaign','ACTIVE',?,?)`, today, today)
	if err != nil {
		return err
	}
	campaign, _ := res.LastInsertId()
	res, err = db.Exec(`INSERT INTO predict_match_days(campaign_id,match_date,status) VALUES (?,?,'PUBLISHED')`, campaign, today)
	if err != nil {
		return err
	}
	day, _ := res.LastInsertId()
	for i, f := range []string{"Simba v Yanga", "Azam v Singida", "Coastal v Namungo"} {
		if _, err := db.Exec(`INSERT INTO predict_fixtures(match_day_id,slot,display_text,kickoff_at,status) VALUES (?,?,?,?,'SCHEDULED')`,
			day, i+1, f, now.Add(time.Duration(3+i)*time.Hour)); err != nil {
			return err
		}
	}
	return nil
}

func summary(db *sql.DB) {
	fmt.Println("\nwhat the services wrote:")
	for _, q := range []struct{ label, sql string }{
		{"subscriptions", `SELECT CONCAT(p.code,' ',ps.state), COUNT(*) FROM product_subscriptions ps JOIN products p ON p.id=ps.product_id GROUP BY 1 ORDER BY 1`},
		{"answers", `SELECT result, COUNT(*) FROM answers GROUP BY 1 ORDER BY 1`},
		{"charges", `SELECT state, COUNT(*) FROM charge_intents GROUP BY 1 ORDER BY 1`},
		{"sms", `SELECT delivery_state, COUNT(*) FROM outbound_messages GROUP BY 1 ORDER BY 1`},
	} {
		rows, err := db.Query(q.sql)
		if err != nil {
			continue
		}
		var parts []string
		for rows.Next() {
			var k sql.NullString
			var n int
			if rows.Scan(&k, &n) == nil {
				parts = append(parts, fmt.Sprintf("%s %d", k.String, n))
			}
		}
		rows.Close()
		fmt.Printf("  %-14s %s\n", q.label, strings.Join(parts, ", "))
	}
	var pts sql.NullInt64
	_ = db.QueryRow(`SELECT SUM(delta_points) FROM point_ledger`).Scan(&pts)
	fmt.Printf("  %-14s %d awarded in total\n", "points", pts.Int64)
}

func openTestDB() *sql.DB {
	dsn := os.Getenv("SEED_MYSQL_DSN")
	cfg, err := driver.ParseDSN(dsn)
	if err != nil || !strings.HasPrefix(cfg.DBName, "yas_test_") {
		fail("SEED_MYSQL_DSN must select a yas_test_ database")
	}
	store, err := mysqlstore.Open(dsn)
	if err != nil {
		fail("database unavailable")
	}
	var name string
	if err := store.DB.QueryRow(`SELECT DATABASE()`).Scan(&name); err != nil || name != cfg.DBName {
		fail("connected database differs from SEED_MYSQL_DSN")
	}
	return store.DB
}

func fail(msg string) {
	fmt.Fprintln(os.Stderr, "yas-seed:", msg)
	os.Exit(2)
}
