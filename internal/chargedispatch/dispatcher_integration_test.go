package chargedispatch

import (
	"context"
	"crypto/md5"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync/atomic"
	"testing"

	driver "github.com/go-sql-driver/mysql"
	"yas-modernization/internal/callbacks"
	mysqlstore "yas-modernization/internal/store/mysql"
	"yas-modernization/internal/workers/sokamo"
)

func TestConfigRejectsRemotePlainHTTP(t *testing.T) {
	c := Config{Endpoint: "http://provider.example/charge", CPID: "86", Password: "test", Username: "test", BearerToken: "test", SourceAddress: "127.0.0.1"}
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
	for _, table := range []string{"processed_events", "charge_intents", "payment_attempts", "interactions", "product_subscriptions", "callback_outbox", "callback_events", "products", "subscribers"} {
		if _, err := store.DB.ExecContext(ctx, "DELETE FROM "+table); err != nil {
			t.Fatal(table, err)
		}
	}
	if _, err := store.DB.ExecContext(ctx, `INSERT INTO products(id,code,display_name) VALUES (1,'soka','Soka')`); err != nil {
		t.Fatal(err)
	}
	if _, err := store.DB.ExecContext(ctx, `INSERT INTO subscribers(id,msisdn) VALUES (1,'255700000001')`); err != nil {
		t.Fatal(err)
	}
	if _, err := store.DB.ExecContext(ctx, `INSERT INTO product_subscriptions(product_id,subscriber_id,state,billing_segment,started_at)
		VALUES (1,1,'ACTIVE','NORMAL',UTC_TIMESTAMP(6))`); err != nil {
		t.Fatal(err)
	}
	seedMO := func(request string) int64 {
		t.Helper()
		body := fmt.Sprintf(`{"requestId":%q,"requestParam":{"data":[{"name":"Msisdn","value":"255700000001"},{"name":"Type","value":"InteractiveResponse"},{"name":"CustomerResponse","value":"P300"}]}}`, request)
		event, err := callbacks.ParseJSON("soka", []byte(body))
		if err != nil {
			t.Fatal(err)
		}
		id, err := store.Save(ctx, event)
		if err != nil {
			t.Fatal(err)
		}
		if err := (sokamo.Processor{DB: store.DB}).Process(ctx, id); err != nil {
			t.Fatal(err)
		}
		var intentID int64
		if err := store.DB.QueryRowContext(ctx, `SELECT id FROM charge_intents WHERE source_callback_event_id=?`, id).Scan(&intentID); err != nil {
			t.Fatal(err)
		}
		return intentID
	}
	firstIntent := seedMO("MO-CHARGE-1")
	var calls atomic.Int64
	var requestID atomic.Value
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls.Add(1)
		if r.Method != http.MethodPost || r.Header.Get("Authorization") != "Bearer test-token" {
			t.Errorf("unexpected request method or authorization")
		}
		var body struct {
			Password     string `json:"password"`
			RequestID    string `json:"requestId"`
			Stamp        string `json:"requestTimeStamp"`
			Operation    string `json:"operation"`
			RequestParam struct {
				Data []struct{ Name, Value string } `json:"data"`
			} `json:"requestParam"`
		}
		if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
			t.Error(err)
		}
		requestID.Store(body.RequestID)
		wantHash := md5.Sum([]byte("86" + "test-password" + body.Stamp))
		if body.Password != hex.EncodeToString(wantHash[:]) || body.Operation != "Payment" || !strings.HasPrefix(body.RequestID, "PU") {
			t.Errorf("request envelope does not match legacy payment shape")
		}
		fields := map[string]string{}
		for _, field := range body.RequestParam.Data {
			fields[field.Name] = field.Value
		}
		if fields["ChargeAmount"] != "300" || fields["OfferCode"] != "017008600005" || fields["Msisdn"] != "255700000001" {
			t.Errorf("charge fields do not match pending payment")
		}
		w.WriteHeader(http.StatusAccepted)
	}))
	defer server.Close()
	configuration := Config{Endpoint: server.URL, CPID: "86", Password: "test-password", Username: "test-user", BearerToken: "test-token", SourceAddress: "127.0.0.1"}
	d := Dispatcher{DB: store.DB, Config: configuration}
	didWork, err := d.DispatchOne(ctx)
	if err != nil || !didWork {
		t.Fatalf("dispatch worked=%v err=%v", didWork, err)
	}
	if didWork, err := d.DispatchOne(ctx); err != nil || didWork {
		t.Fatalf("repeat worked=%v err=%v", didWork, err)
	}
	var state string
	var attempts int
	if err := store.DB.QueryRowContext(ctx, `SELECT state,attempts FROM charge_intents WHERE id=?`, firstIntent).Scan(&state, &attempts); err != nil || state != "SUBMITTED" || attempts != 1 || calls.Load() != 1 || requestID.Load() == nil {
		t.Fatalf("submitted state=%q attempts=%d calls=%d err=%v", state, attempts, calls.Load(), err)
	}
	secondIntent := seedMO("MO-CHARGE-2")
	failing := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls.Add(1)
		w.WriteHeader(http.StatusServiceUnavailable)
	}))
	defer failing.Close()
	d.Config.Endpoint = failing.URL
	if didWork, err := d.DispatchOne(ctx); err != nil || !didWork {
		t.Fatalf("uncertain dispatch worked=%v err=%v", didWork, err)
	}
	if didWork, err := d.DispatchOne(ctx); err != nil || didWork {
		t.Fatalf("uncertain repeat worked=%v err=%v", didWork, err)
	}
	if err := store.DB.QueryRowContext(ctx, `SELECT state,attempts FROM charge_intents WHERE id=?`, secondIntent).Scan(&state, &attempts); err != nil || state != "NEEDS_REVIEW" || attempts != 1 || calls.Load() != 2 {
		t.Fatalf("uncertain state=%q attempts=%d calls=%d err=%v", state, attempts, calls.Load(), err)
	}
}
