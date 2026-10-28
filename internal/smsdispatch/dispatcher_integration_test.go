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
}
