package renewaldispatch

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
	"yas-modernization/internal/callbacks"
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
	for _, q := range []string{`INSERT INTO products(id,code,display_name) VALUES (1,'soka','Soka')`, `INSERT INTO subscribers(id,msisdn) VALUES (1,'255700000001')`} {
		if _, err := store.DB.ExecContext(ctx, q); err != nil {
			t.Fatal(err)
		}
	}
	event, err := callbacks.ParseJSON("soka", []byte(`{"requestId":"RN-1","requestParam":{"data":[{"name":"Msisdn","value":"255700000001"},{"name":"Type","value":"DeActivation"}]}}`))
	if err != nil {
		t.Fatal(err)
	}
	callbackID, err := store.Save(ctx, event)
	if err != nil {
		t.Fatal(err)
	}
	for _, target := range []string{"MANAGE_SUBSCRIPTION", "SUBS_MANAGE", "UNCONFIGURED"} {
		if _, err := store.DB.ExecContext(ctx, `INSERT INTO renewal_requests (product_id,subscriber_id,source_callback_event_id,activity_type,target) VALUES (1,1,?,'UNSUBSCRIPTION',?)`, callbackID, target); err != nil {
			t.Fatal(err)
		}
	}
	var mu sync.Mutex
	hits := map[string]int{}
	var body map[string]string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		hits[r.URL.Path]++
		raw, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(raw, &body)
		if r.URL.Path == "/broken" {
			w.WriteHeader(http.StatusBadGateway)
			return
		}
		w.Write([]byte(`ok`))
	}))
	defer server.Close()
	d := Dispatcher{DB: store.DB, Config: Config{Endpoints: map[string]string{"MANAGE_SUBSCRIPTION": server.URL + "/manage", "SUBS_MANAGE": server.URL + "/broken"}}}
	for i := 0; i < 4; i++ {
		if _, err := d.DispatchOne(ctx); err != nil {
			t.Fatal(err)
		}
	}
	if hits["/manage"] != 1 || hits["/broken"] != 1 {
		t.Fatalf("each request must be sent once, got %v", hits)
	}
	if body["msisdn"] != "255700000001" || body["serviceID"] != "SOKA" || body["activityType"] != "UNSUBSCRIPTION" || len(body) != 3 {
		t.Fatalf("request body %v", body)
	}
	for target, want := range map[string]string{"MANAGE_SUBSCRIPTION": "SENT", "SUBS_MANAGE": "NEEDS_REVIEW", "UNCONFIGURED": "PENDING"} {
		var state string
		if err := store.DB.QueryRowContext(ctx, `SELECT state FROM renewal_requests WHERE target=?`, target).Scan(&state); err != nil || state != want {
			t.Errorf("%s state=%s err=%v, want %s", target, state, err, want)
		}
	}
	if err := (Config{Endpoints: map[string]string{"X": "http://renewal.example/"}}).validate(); err == nil {
		t.Fatal("plain HTTP to a remote host must be refused")
	}
}
