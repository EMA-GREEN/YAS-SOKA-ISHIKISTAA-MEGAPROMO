package callbacks

import (
	"encoding/json"
	"errors"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func TestParseMpushLegacyErrors(t *testing.T) {
	now := time.Date(2026, 10, 28, 12, 0, 0, 0, time.UTC)
	for body, code := range map[string]string{
		``:                          "1001",
		`not json`:                  "1002",
		`{"a":1} {"b":2}`:           "1002",
		`{"msisdn":"255700000001"}`: "1003",
		`{"channel":"SMS"}`:         "1003",
		`{"msisdn":255700000001,"channel":"SMS"}`:   "1003",
		`{"msisdn":"255700000001","channel":"WEB"}`: "1004",
	} {
		_, reply, err := ParseMpush("soka", []byte(body), now)
		if !errors.Is(err, ErrInvalid) || reply == nil || reply.StatusCode != code || reply.Status != "1" {
			t.Errorf("%q: reply=%+v err=%v, want code %s", body, reply, err, code)
		}
	}
}

func TestParseMpushIdentity(t *testing.T) {
	day1 := time.Date(2026, 10, 28, 20, 0, 0, 0, time.UTC) // 23:00 in Dar es Salaam
	day2 := day1.Add(2 * time.Hour)                        // 01:00 next day there
	a, _, err := ParseMpush("soka", []byte(`{"msisdn":" 255700000001 ","channel":"sms","ussd_data":"*150*1#"}`), day1)
	if err != nil {
		t.Fatal(err)
	}
	if a.Kind != KindMpushSubsInit || a.MSISDN != "255700000001" || !strings.HasPrefix(a.RequestID, "mpush:20261028:") {
		t.Fatalf("event %+v", a)
	}
	var payload map[string]string
	if err := json.Unmarshal(a.Payload, &payload); err != nil || payload["channel"] != "SMS" {
		t.Fatalf("payload %s", a.Payload)
	}
	upper, _, _ := ParseMpush("soka", []byte(`{"MSISDN":"255700000001","CHANNEL":"SMS","USSD_DATA":"*150*1#"}`), day1)
	if upper.RequestID != a.RequestID {
		t.Fatal("upper-case keys must give the same identity")
	}
	next, _, _ := ParseMpush("soka", []byte(`{"msisdn":"255700000001","channel":"SMS","ussd_data":"*150*1#"}`), day2)
	if next.RequestID == a.RequestID {
		t.Fatal("a request on the next Dar es Salaam day must be new")
	}
	otherUSSD, _, _ := ParseMpush("soka", []byte(`{"msisdn":"255700000001","channel":"SMS","ussd_data":"*150*2#"}`), day1)
	if otherUSSD.RequestID == a.RequestID {
		t.Fatal("different ussd_data must be a different Soka request")
	}
	otherProduct, _, _ := ParseMpush("ishikistaa", []byte(`{"msisdn":"255700000001","channel":"SMS","ussd_data":"*150*1#"}`), day1)
	if otherProduct.RequestID == a.RequestID {
		t.Fatal("identity must include the product")
	}
	m1, _, _ := ParseMpush("megapromo", []byte(`{"msisdn":"255700000001","channel":"SMS","ussd_data":"x"}`), day1)
	m2, _, _ := ParseMpush("megapromo", []byte(`{"msisdn":"255700000001","channel":"SMS"}`), day1)
	if m1.RequestID != m2.RequestID {
		t.Fatal("MegaPromo ignores ussd_data, as in PHP")
	}
}

func TestParseTigoPesaPush(t *testing.T) {
	for _, body := range []string{`{"ReferenceID":"GTL1","Status":true}`, `{"ReferenceID":"GTL1","Status":"false","Description":"x"}`, `{"ReferenceID":"GTL1","Status":" TRUE "}`} {
		event, err := ParseTigoPesaPush([]byte(body))
		if err != nil || event.Product != "megapromo" || event.Kind != KindTigoPesaResult || event.RequestID != "GTL1" {
			t.Errorf("%s: %+v %v", body, event, err)
		}
	}
	for _, body := range []string{`{}`, `{"Status":true}`, `{"ReferenceID":"","Status":true}`, `{"ReferenceID":7,"Status":true}`,
		`{"ReferenceID":"GTL1"}`, `{"ReferenceID":"GTL1","Status":1}`, `{"ReferenceID":"GTL1","Status":"yes"}`, `{"ReferenceID":"GTL1","Status":true} {}`} {
		if _, err := ParseTigoPesaPush([]byte(body)); !errors.Is(err, ErrInvalid) {
			t.Errorf("%s: expected invalid, got %v", body, err)
		}
	}
}

func TestExtraRoutes(t *testing.T) {
	store := &fakeStore{id: 5}
	location, _ := time.LoadLocation("Africa/Dar_es_Salaam")
	h := Handler{Store: store, Log: slog.Default(), Location: location}.Routes()
	post := func(path, body string) *httptest.ResponseRecorder {
		w := httptest.NewRecorder()
		h.ServeHTTP(w, httptest.NewRequest(http.MethodPost, path, strings.NewReader(body)))
		return w
	}
	for _, path := range []string{"/soka-mpush/notification", "/ishikistaa-mpush/notification", "/megapromo-mpush/notification"} {
		w := post(path, `{"msisdn":"255700000001","channel":"USSD"}`)
		if w.Code != http.StatusOK || strings.TrimSpace(w.Body.String()) != `{"status":"0","statusCode":"0000","description":"Success"}` {
			t.Errorf("%s: %d %s", path, w.Code, w.Body)
		}
		w = post(path, `{"msisdn":"255700000001","channel":"FAX"}`)
		if w.Code != http.StatusOK || !strings.Contains(w.Body.String(), `"statusCode":"1004"`) {
			t.Errorf("%s legacy error: %d %s", path, w.Code, w.Body)
		}
	}
	// A reference containing JSON syntax is escaped, unlike the PHP reply.
	w := post("/notification/megapromo/tigopesapush/collection", `{"ReferenceID":"GTL\"}, \"x\":\"1","Status":true}`)
	var reply map[string]any
	if w.Code != http.StatusOK || json.Unmarshal(w.Body.Bytes(), &reply) != nil || reply["ReferenceID"] != `GTL"}, "x":"1` ||
		reply["ResponseCode"] != "BILLER-30-0000-S" || reply["ResponseStatus"] != true || len(reply) != 4 {
		t.Fatalf("push reply %d %s", w.Code, w.Body)
	}
	if w := post("/notification/megapromo/mixxpush/collection", `{"ReferenceID":"GTL2","Status":false}`); w.Code != http.StatusOK {
		t.Fatalf("mixx route %d", w.Code)
	}
	if w := post("/notification/megapromo/tigopesapush/collection", `{"Status":true}`); w.Code != http.StatusBadRequest {
		t.Fatalf("invalid push %d", w.Code)
	}
	saved := store.saved
	store.err = errors.New("database down")
	if w := post("/notification/megapromo/tigopesapush/collection", `{"ReferenceID":"GTL3","Status":true}`); w.Code != http.StatusServiceUnavailable || strings.Contains(w.Body.String(), "BILLER") {
		t.Fatalf("store failure must not acknowledge: %d %s", w.Code, w.Body)
	}
	if w := post("/soka-mpush/notification", `{"msisdn":"255700000001","channel":"SMS"}`); w.Code != http.StatusServiceUnavailable {
		t.Fatalf("mpush store failure %d", w.Code)
	}
	if store.saved != saved+2 {
		t.Fatal("save not attempted")
	}
	for _, path := range []string{"/notification/megapromo/tigopesa/collection", "/notification/megapromo/tigopesapush/testcollection", "/notification/megapromo-test", "/test/123"} {
		if w := post(path, `{}`); w.Code != http.StatusNotFound && w.Code != http.StatusMethodNotAllowed {
			t.Errorf("%s must not be served: %d", path, w.Code)
		}
	}
}
