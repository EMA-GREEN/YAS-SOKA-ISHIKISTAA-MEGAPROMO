package callbacks

import (
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

const sample = `{"requestId":"S123","requestTimeStamp":"20261028120000","requestParam":{"data":[{"name":"Msisdn","value":"255700000001"},{"name":"Type","value":"Payment"},{"name":"ChargeAmount","value":"300"}]}}`

func TestParseJSON(t *testing.T) {
	event, err := ParseJSON("soka", []byte(sample))
	if err != nil {
		t.Fatal(err)
	}
	if event.Product != "soka" || event.RequestID != "S123" || event.Kind != "Payment" || event.MSISDN != "255700000001" {
		t.Fatalf("unexpected event: %+v", event)
	}
	reordered := `{"requestParam":{"data":[{"value":"255700000001","name":"Msisdn"},{"value":"Payment","name":"Type"},{"value":"300","name":"ChargeAmount"}]},"requestTimeStamp":"20261028120000","requestId":"S123"}`
	second, err := ParseJSON("soka", []byte(reordered))
	if err != nil || event.Hash != second.Hash {
		t.Fatal("equivalent JSON must hash identically")
	}
}

func TestParseJSONRejectsMalformedInput(t *testing.T) {
	for _, body := range []string{
		`{}`, sample + ` {}`, `{"requestId":"x","requestParam":{"data":[{"name":"Msisdn","value":"1"},{"name":"Msisdn","value":"2"},{"name":"Type","value":"Payment"}]}}`,
	} {
		if _, err := ParseJSON("soka", []byte(body)); !errors.Is(err, ErrInvalid) {
			t.Fatalf("expected invalid for %q: %v", body, err)
		}
	}
}

type fakeStore struct {
	id    int64
	err   error
	saved int
}

func (f *fakeStore) Save(_ context.Context, _ Event) (int64, error) { f.saved++; return f.id, f.err }
func (f *fakeStore) Ready(context.Context) error                    { return f.err }

func TestHandlerAcknowledgesOnlySavedCallback(t *testing.T) {
	store := &fakeStore{id: 42}
	location, _ := time.LoadLocation("Africa/Dar_es_Salaam")
	h := Handler{Store: store, Log: slog.Default(), Location: location}.Routes()
	req := httptest.NewRequest(http.MethodPost, "/soka-trivia/notification", strings.NewReader(sample))
	w := httptest.NewRecorder()
	h.ServeHTTP(w, req)
	if w.Code != http.StatusOK || store.saved != 1 {
		t.Fatalf("status=%d saved=%d", w.Code, store.saved)
	}
	var got map[string]any
	if err := json.Unmarshal(w.Body.Bytes(), &got); err != nil {
		t.Fatal(err)
	}
	if got["requestId"] != "S123" || got["responseId"] != float64(42) || got["operation"] != "CP_NOTIFICATION" {
		t.Fatalf("unexpected response: %v", got)
	}
	store.err = errors.New("database down")
	w = httptest.NewRecorder()
	h.ServeHTTP(w, httptest.NewRequest(http.MethodPost, "/soka-trivia/notification", strings.NewReader(sample)))
	if w.Code != http.StatusServiceUnavailable {
		t.Fatalf("expected retryable response, got %d", w.Code)
	}
}
