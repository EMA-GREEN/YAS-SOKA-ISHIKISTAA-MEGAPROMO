package smsdispatch

import (
	"context"
	"crypto/md5"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync"
	"sync/atomic"
	"testing"

	driver "github.com/go-sql-driver/mysql"
	mysqlstore "yas-modernization/internal/store/mysql"
	"yas-modernization/internal/testdb"
)

func TestConfigRejectsRemotePlainHTTP(t *testing.T) {
	c := Config{Endpoint: "http://provider.example/sms", CPID: "86", Password: "test", Username: "test", BearerToken: "test", SourceAddress: "127.0.0.1"}
	if err := c.validate(); err == nil {
		t.Fatal("remote plain HTTP accepted")
	}
}

func TestDispatchIntegration(t *testing.T) {
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
	seed := func(text string) int64 {
		t.Helper()
		result, err := store.DB.ExecContext(ctx, `INSERT INTO outbound_messages(product_id,subscriber_id,message_kind,body_text,delivery_state)
			VALUES (1,1,'POINT_PURCHASE_RESULT',?,'PENDING')`, text)
		if err != nil {
			t.Fatal(err)
		}
		id, err := result.LastInsertId()
		if err != nil {
			t.Fatal(err)
		}
		return id
	}
	first := seed("Synthetic purchase result")
	var calls atomic.Int64
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls.Add(1)
		if r.Method != http.MethodPost || r.Header.Get("Authorization") != "Bearer test-token" {
			t.Error("request method or auth")
		}
		var body struct {
			RequestID    string `json:"requestId"`
			Stamp        string `json:"requestTimeStamp"`
			Password     string `json:"password"`
			Operation    string `json:"operation"`
			RequestParam struct {
				Data []struct{ Name, Value string } `json:"data"`
			} `json:"requestParam"`
		}
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
			t.Error(err)
		}
		want := md5.Sum([]byte("86" + "test-password" + body.Stamp))
		if body.Password != hex.EncodeToString(want[:]) || body.Operation != "InteractiveRequest" || !strings.HasPrefix(body.RequestID, "I") {
			t.Error("unexpected SMS envelope")
		}
		fields := map[string]string{}
		for _, field := range body.RequestParam.Data {
			fields[field.Name] = field.Value
		}
		if fields["Content"] != "Synthetic purchase result" || fields["OfferCode"] != "017008600010" || fields["Msisdn"] != "255700000001" {
			t.Error("unexpected SMS fields")
		}
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"responseParam":{"status":"0"}}`))
	}))
	defer server.Close()
	d := Dispatcher{DB: store.DB, Config: Config{Endpoint: server.URL, CPID: "86", Password: "test-password", Username: "test-user", BearerToken: "test-token", SourceAddress: "127.0.0.1"}}
	if worked, err := d.DispatchOne(ctx); err != nil || !worked {
		t.Fatalf("accepted worked=%v err=%v", worked, err)
	}
	if worked, err := d.DispatchOne(ctx); err != nil || worked {
		t.Fatalf("accepted replay worked=%v err=%v", worked, err)
	}
	var state string
	if err := store.DB.QueryRowContext(ctx, `SELECT delivery_state FROM outbound_messages WHERE id=?`, first).Scan(&state); err != nil || state != "ACCEPTED" || calls.Load() != 1 {
		t.Fatalf("accepted state=%q calls=%d err=%v", state, calls.Load(), err)
	}
	var events int64
	if err := store.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM outbound_message_events WHERE outbound_message_id=?`, first).Scan(&events); err != nil || events != 1 {
		t.Fatalf("accepted events=%d err=%v", events, err)
	}
	second := seed("Synthetic second result")
	failing := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls.Add(1)
		w.WriteHeader(http.StatusServiceUnavailable)
	}))
	defer failing.Close()
	d.Config.Endpoint = failing.URL
	if worked, err := d.DispatchOne(ctx); err != nil || !worked {
		t.Fatalf("uncertain worked=%v err=%v", worked, err)
	}
	if worked, err := d.DispatchOne(ctx); err != nil || worked {
		t.Fatalf("uncertain replay worked=%v err=%v", worked, err)
	}
	if err := store.DB.QueryRowContext(ctx, `SELECT delivery_state FROM outbound_messages WHERE id=?`, second).Scan(&state); err != nil || state != "NEEDS_REVIEW" || calls.Load() != 2 {
		t.Fatalf("uncertain state=%q calls=%d err=%v", state, calls.Load(), err)
	}

	// An explicit provider refusal is resent once, after a delay, with a new request ID.
	var mu sync.Mutex
	var requestIDs []string
	answers := []string{`{"responseParam":{"status":"1","statusCode":"1001"}}`}
	scripted := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var body struct {
			RequestID string `json:"requestId"`
		}
		_ = json.NewDecoder(r.Body).Decode(&body)
		mu.Lock()
		defer mu.Unlock()
		requestIDs = append(requestIDs, body.RequestID)
		reply := answers[0]
		if len(answers) > 1 {
			answers = answers[1:]
		}
		_, _ = w.Write([]byte(reply))
	}))
	defer scripted.Close()
	d.Config.Endpoint = scripted.URL
	mustState := func(id int64, want string) {
		t.Helper()
		var got string
		if err := store.DB.QueryRowContext(ctx, `SELECT delivery_state FROM outbound_messages WHERE id=?`, id).Scan(&got); err != nil || got != want {
			t.Fatalf("message %d state=%q err=%v, want %s", id, got, err, want)
		}
	}
	dueNow := func(id int64) {
		t.Helper()
		if _, err := store.DB.ExecContext(ctx, `UPDATE outbound_messages SET next_attempt_at=DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE id=?`, id); err != nil {
			t.Fatal(err)
		}
	}
	answers = []string{`{"responseParam":{"status":"1","statusCode":"1001"}}`, `{"responseParam":{"status":"0"}}`}
	third := seed("Synthetic refused then accepted")
	if worked, err := d.DispatchOne(ctx); err != nil || !worked {
		t.Fatalf("refused worked=%v err=%v", worked, err)
	}
	mustState(third, "PENDING")
	if worked, err := d.DispatchOne(ctx); err != nil || worked {
		t.Fatal("a resend must wait for its delay")
	}
	dueNow(third)
	if worked, err := d.DispatchOne(ctx); err != nil || !worked {
		t.Fatalf("resend worked=%v err=%v", worked, err)
	}
	mustState(third, "ACCEPTED")
	if len(requestIDs) != 2 || !strings.HasPrefix(requestIDs[0], "I") || !strings.HasPrefix(requestIDs[1], "IR") || requestIDs[0] == requestIDs[1] {
		t.Fatalf("request IDs %v", requestIDs)
	}
	// Refused twice: the message is closed as REJECTED, not sent a third time.
	answers = []string{`{"responseParam":{"status":"1","statusCode":"1001"}}`}
	fourth := seed("Synthetic refused twice")
	if _, err := d.DispatchOne(ctx); err != nil {
		t.Fatal(err)
	}
	dueNow(fourth)
	if _, err := d.DispatchOne(ctx); err != nil {
		t.Fatal(err)
	}
	mustState(fourth, "REJECTED")
	dueNow(fourth)
	if worked, _ := d.DispatchOne(ctx); worked {
		t.Fatal("a rejected message must not be sent again")
	}
	// PHP only resends on the day the SMS was created.
	fifth := seed("Synthetic stale refusal")
	if _, err := store.DB.ExecContext(ctx, `UPDATE outbound_messages SET created_at=DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 2 DAY) WHERE id=?`, fifth); err != nil {
		t.Fatal(err)
	}
	if _, err := d.DispatchOne(ctx); err != nil {
		t.Fatal(err)
	}
	mustState(fifth, "REJECTED")
	var resendEvents int64
	if err := store.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM outbound_message_events WHERE event_kind='REJECTED_RETRY'`).Scan(&resendEvents); err != nil || resendEvents != 2 {
		t.Fatalf("resend events=%d err=%v", resendEvents, err)
	}
}
