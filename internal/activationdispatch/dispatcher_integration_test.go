package activationdispatch

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync"
	"testing"

	driver "github.com/go-sql-driver/mysql"
	mysqlstore "yas-modernization/internal/store/mysql"
	"yas-modernization/internal/testdb"
)

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
	if err := testdb.Reset(ctx, store.DB); err != nil {
		t.Fatal(err)
	}
	for _, q := range []string{
		`INSERT INTO products(id,code,display_name) VALUES (1,'soka','Soka')`,
		`INSERT INTO subscribers(id,msisdn) VALUES (1,'255700000001'),(2,'255700000002')`,
		`INSERT INTO product_subscriptions(id,product_id,subscriber_id,state,billing_segment) VALUES (1,1,1,'INACTIVE','NORMAL'),(2,1,2,'INACTIVE','NORMAL')`,
		`INSERT INTO activation_attempts(id,subscription_id,product_id,subscriber_id,external_request_id,channel,state,requested_at)
			VALUES (1,1,1,1,'MP-1','USSD','PENDING',UTC_TIMESTAMP(6)),(2,2,1,2,'MP-2','SMS','PENDING',UTC_TIMESTAMP(6))`,
	} {
		if _, err := store.DB.ExecContext(ctx, q); err != nil {
			t.Fatal(q, err)
		}
	}
	var mu sync.Mutex
	var bodies []map[string]any
	var tokens []string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		raw, _ := io.ReadAll(r.Body)
		var body map[string]any
		_ = json.Unmarshal(raw, &body)
		bodies = append(bodies, body)
		tokens = append(tokens, r.Header.Get("X-Authorization"))
		if len(bodies) == 1 {
			w.Write([]byte(`{"responseParam":{"status":"0","statusCode":"0000"}}`))
			return
		}
		w.Write([]byte(`{"responseParam":{"status":"1","statusCode":"1004"}}`))
	}))
	defer server.Close()
	d := Dispatcher{DB: store.DB, Config: Config{Endpoint: server.URL, CPID: "1", Password: "test-only", Username: "u", AccessToken: "test-token", SourceAddress: "127.0.0.1"}}
	for i := 0; i < 3; i++ {
		if _, err := d.DispatchOne(ctx); err != nil {
			t.Fatal(err)
		}
	}
	if len(bodies) != 2 {
		t.Fatalf("each activation must be sent once, got %d", len(bodies))
	}
	if bodies[0]["operation"] != "ACTIVATE" || bodies[0]["requestId"] != "MP-1" || tokens[0] != "Bearer test-token" {
		t.Fatalf("request %v token %q", bodies[0], tokens[0])
	}
	data, _ := json.Marshal(bodies[0]["requestParam"])
	if !strings.Contains(string(data), `"255700000001"`) || !strings.Contains(string(data), OfferCode) {
		t.Fatalf("request data %s", data)
	}
	// An Ishikistaa activation uses Ishikistaa's offer.
	for _, q := range []string{
		`INSERT INTO products(id,code,display_name) VALUES (2,'ishikistaa','Ishikistaa')`,
		`INSERT INTO product_subscriptions(id,product_id,subscriber_id,state,billing_segment) VALUES (3,2,1,'INACTIVE','NORMAL')`,
		`INSERT INTO activation_attempts(id,subscription_id,product_id,subscriber_id,external_request_id,channel,state,requested_at)
			VALUES (3,3,2,1,'MP-3','SMS','PENDING',UTC_TIMESTAMP(6))`,
	} {
		if _, err := store.DB.ExecContext(ctx, q); err != nil {
			t.Fatal(q, err)
		}
	}
	if _, err := d.DispatchOne(ctx); err != nil {
		t.Fatal(err)
	}
	if len(bodies) != 3 {
		t.Fatalf("Ishikistaa activation not sent: %d", len(bodies))
	}
	ishiData, _ := json.Marshal(bodies[2]["requestParam"])
	if !strings.Contains(string(ishiData), "017008600009") {
		t.Fatalf("Ishikistaa offer missing: %s", ishiData)
	}
	for id, want := range map[int]string{1: "SENT", 2: "NEEDS_REVIEW"} {
		var state, provider string
		if err := store.DB.QueryRowContext(ctx, `SELECT state, provider_status FROM activation_attempts WHERE id=?`, id).Scan(&state, &provider); err != nil || state != want {
			t.Errorf("attempt %d state=%s provider=%s err=%v, want %s", id, state, provider, err, want)
		}
	}
}
