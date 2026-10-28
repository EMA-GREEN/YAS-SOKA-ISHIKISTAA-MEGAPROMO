// Command yas-loadgen sends synthetic Soka MO callbacks to a callback server
// and reports intake latency and end-to-end processing time. It is for
// isolated test environments only: it refuses non-loopback targets unless
// -allow-remote is set, and any database not named yas_test_*.
//
// Traffic is a mix of answers (charge intents, never dispatched by this tool)
// and HELP keyword replies from seeded synthetic subscribers.
package main

import (
	"bytes"
	"context"
	"database/sql"
	"flag"
	"fmt"
	"net"
	"net/http"
	"net/url"
	"os"
	"sort"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	driver "github.com/go-sql-driver/mysql"
	mysqlstore "yas-modernization/internal/store/mysql"
)

const syntheticPrefix = "2557998" // synthetic MSISDNs; the system tests use 2557999

func main() {
	target := flag.String("target", "http://127.0.0.1:18080/soka-trivia/notification", "callback endpoint")
	token := flag.String("token", "", "X-Callback-Token, if the ingress policy needs one")
	total := flag.Int("n", 1000, "callbacks to send")
	workers := flag.Int("c", 20, "concurrent senders")
	subscribers := flag.Int("subscribers", 200, "synthetic subscribers to spread traffic over")
	answerShare := flag.Float64("answers", 0.7, "share of callbacks that are answers; the rest are HELP")
	seed := flag.Bool("seed", false, "create the synthetic product, question, and subscribers (needs LOADGEN_MYSQL_DSN)")
	wait := flag.Duration("wait", 0, "after sending, wait up to this long for the MO worker to process everything (needs LOADGEN_MYSQL_DSN)")
	allowRemote := flag.Bool("allow-remote", false, "allow a non-loopback target")
	runID := flag.String("run", "LOAD"+time.Now().UTC().Format("150405"), "request ID prefix for this run")
	flag.Parse()

	u, err := url.Parse(*target)
	if err != nil || u.Host == "" {
		fail("invalid -target")
	}
	if ip := net.ParseIP(u.Hostname()); !*allowRemote && u.Hostname() != "localhost" && (ip == nil || !ip.IsLoopback()) {
		fail("refusing non-loopback target without -allow-remote")
	}
	if *total < 1 || *workers < 1 || *subscribers < 1 || *answerShare < 0 || *answerShare > 1 {
		fail("invalid -n, -c, -subscribers, or -answers")
	}
	var db *sql.DB
	if *seed || *wait > 0 {
		db = openTestDB()
		defer db.Close()
	}
	if *seed {
		if err := seedData(db, *subscribers); err != nil {
			fail("seed: " + err.Error())
		}
		fmt.Printf("seeded %d synthetic subscribers\n", *subscribers)
	}

	client := &http.Client{Timeout: 15 * time.Second, Transport: &http.Transport{MaxIdleConnsPerHost: *workers}}
	jobs := make(chan int)
	latencies := make([]time.Duration, *total)
	var ok, failed atomic.Int64
	statuses := sync.Map{}
	var wg sync.WaitGroup
	started := time.Now()
	for w := 0; w < *workers; w++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for i := range jobs {
				text := "help"
				if float64(i%100) < *answerShare*100 {
					text = "A"
				}
				msisdn := fmt.Sprintf("%s%05d", syntheticPrefix, i%*subscribers)
				body := fmt.Sprintf(`{"requestId":"%s-%d","requestParam":{"data":[{"name":"Msisdn","value":%q},{"name":"Type","value":"InteractiveResponse"},{"name":"CustomerResponse","value":%q}]}}`,
					*runID, i, msisdn, text)
				req, _ := http.NewRequest(http.MethodPost, *target, bytes.NewReader([]byte(body)))
				req.Header.Set("Content-Type", "application/json")
				if *token != "" {
					req.Header.Set("X-Callback-Token", *token)
				}
				t0 := time.Now()
				resp, err := client.Do(req)
				latencies[i] = time.Since(t0)
				code := 0
				if err == nil {
					code = resp.StatusCode
					resp.Body.Close()
				}
				if code == http.StatusOK {
					ok.Add(1)
				} else {
					failed.Add(1)
				}
				v, _ := statuses.LoadOrStore(code, new(atomic.Int64))
				v.(*atomic.Int64).Add(1)
			}
		}()
	}
	for i := 0; i < *total; i++ {
		jobs <- i
	}
	close(jobs)
	wg.Wait()
	elapsed := time.Since(started)

	sort.Slice(latencies, func(a, b int) bool { return latencies[a] < latencies[b] })
	pct := func(p float64) time.Duration { return latencies[int(p*float64(len(latencies)-1))] }
	fmt.Printf("sent %d callbacks with %d senders in %s: %.0f/s\n", *total, *workers, elapsed.Round(time.Millisecond), float64(*total)/elapsed.Seconds())
	fmt.Printf("intake latency p50=%s p90=%s p99=%s max=%s\n", pct(0.5).Round(time.Microsecond*100), pct(0.9).Round(time.Microsecond*100), pct(0.99).Round(time.Microsecond*100), latencies[len(latencies)-1].Round(time.Microsecond*100))
	var codes []string
	statuses.Range(func(k, v any) bool {
		codes = append(codes, fmt.Sprintf("%v:%d", k, v.(*atomic.Int64).Load()))
		return true
	})
	sort.Strings(codes)
	fmt.Printf("HTTP status counts %s (accepted %d, other %d)\n", strings.Join(codes, " "), ok.Load(), failed.Load())

	if *wait > 0 {
		deadline := started.Add(elapsed + *wait)
		for {
			var done int64
			if err := db.QueryRow(`SELECT COUNT(*) FROM processed_events pe JOIN callback_events ce ON ce.id=pe.callback_event_id
				WHERE pe.consumer_name='soka-point-mo' AND ce.external_request_id LIKE ?`, *runID+"-%").Scan(&done); err != nil {
				fail("progress query: " + err.Error())
			}
			if done >= ok.Load() {
				total := time.Since(started)
				fmt.Printf("end to end: %d processed by the MO worker in %s: %.0f/s\n", done, total.Round(time.Millisecond), float64(done)/total.Seconds())
				return
			}
			if time.Now().After(deadline) {
				fmt.Printf("end to end: only %d of %d processed within the wait\n", done, ok.Load())
				os.Exit(1)
			}
			time.Sleep(200 * time.Millisecond)
		}
	}
}

func openTestDB() *sql.DB {
	dsn := os.Getenv("LOADGEN_MYSQL_DSN")
	cfg, err := driver.ParseDSN(dsn)
	if err != nil || !strings.HasPrefix(cfg.DBName, "yas_test_") {
		fail("LOADGEN_MYSQL_DSN must select a yas_test_ database")
	}
	store, err := mysqlstore.Open(dsn)
	if err != nil {
		fail("database unavailable")
	}
	var name string
	if err := store.DB.QueryRow(`SELECT DATABASE()`).Scan(&name); err != nil || name != cfg.DBName {
		fail("connected database differs from LOADGEN_MYSQL_DSN")
	}
	return store.DB
}

// seedData creates the Soka product if needed, one answerable question, and
// active synthetic subscribers on it.
func seedData(db *sql.DB, n int) error {
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
	defer cancel()
	for _, q := range []string{
		`INSERT IGNORE INTO products(id,code,display_name) VALUES (1,'soka','Soka')`,
		`INSERT IGNORE INTO questions(id,product_id,question_text,answer_key,points) VALUES (9001,1,'LOAD-Q','A',100)`,
	} {
		if _, err := db.ExecContext(ctx, q); err != nil {
			return err
		}
	}
	for i := 0; i < n; i++ {
		msisdn := fmt.Sprintf("%s%05d", syntheticPrefix, i)
		res, err := db.ExecContext(ctx, `INSERT INTO subscribers(msisdn) VALUES (?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)`, msisdn)
		if err != nil {
			return err
		}
		id, err := res.LastInsertId()
		if err != nil {
			return err
		}
		if _, err := db.ExecContext(ctx, `INSERT INTO product_subscriptions(product_id,subscriber_id,state,billing_segment,started_at,current_question_id)
			VALUES (1,?,'ACTIVE','NORMAL',UTC_TIMESTAMP(6),9001)
			ON DUPLICATE KEY UPDATE state='ACTIVE', billing_segment='NORMAL', current_question_id=9001`, id); err != nil {
			return err
		}
	}
	return nil
}

func fail(msg string) {
	fmt.Fprintln(os.Stderr, "yas-loadgen:", msg)
	os.Exit(2)
}
