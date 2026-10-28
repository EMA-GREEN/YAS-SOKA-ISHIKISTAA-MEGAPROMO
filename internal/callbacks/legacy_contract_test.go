package callbacks

import (
	"encoding/json"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"regexp"
	"strings"
	"testing"
	"time"
)

func TestLegacyNotificationResponseAcrossProducts(t *testing.T) {
	location, err := time.LoadLocation("Africa/Dar_es_Salaam")
	if err != nil {
		t.Fatal(err)
	}
	for _, tc := range []struct{ path, product string }{
		{"/notification/soka-trivia/billing", "soka"},
		{"/soka-trivia/notification", "soka"},
		{"/ishikistaa/notification", "ishikistaa"},
		{"/notification/megapromo", "megapromo"},
	} {
		t.Run(tc.path, func(t *testing.T) {
			store := &fakeStore{id: 73}
			h := Handler{Store: store, Log: slog.Default(), Location: location}.Routes()
			w := httptest.NewRecorder()
			h.ServeHTTP(w, httptest.NewRequest(http.MethodPost, tc.path, strings.NewReader(sample)))
			if w.Code != http.StatusOK || store.saved != 1 {
				t.Fatalf("status=%d saved=%d", w.Code, store.saved)
			}
			var got struct {
				RequestID         string `json:"requestId"`
				ResponseID        int64  `json:"responseId"`
				ResponseTimeStamp string `json:"responseTimeStamp"`
				Operation         string `json:"operation"`
				ResponseParam     struct {
					Status      string `json:"status"`
					StatusCode  string `json:"statusCode"`
					Description string `json:"description"`
				} `json:"responseParam"`
			}
			if err := json.Unmarshal(w.Body.Bytes(), &got); err != nil {
				t.Fatal(err)
			}
			if got.RequestID != "S123" || got.ResponseID != 73 || got.Operation != "CP_NOTIFICATION" ||
				got.ResponseParam.Status != "0" || got.ResponseParam.StatusCode != "0000" || got.ResponseParam.Description != "Success" ||
				!regexp.MustCompile(`^\d{14}$`).MatchString(got.ResponseTimeStamp) {
				t.Fatalf("unexpected legacy response: %+v", got)
			}
		})
	}
}

func TestPaymentProjectionIsBuiltFromSourceEnvelope(t *testing.T) {
	for _, tc := range []struct {
		product, requestID, amount, reason, status, channel, stream string
	}{
		{"soka", "WW1", `"300"`, "", "SUCCESS", "WEB", "INTERACTION"},
		{"ishikistaa", "GS1", `0`, "", "FAILED", "RENEWAL", "INTERACTION"},
		{"megapromo", "RMPR1", `"300"`, "Low balance", "FAILED", "RETRY", "INTERACTION"},
	} {
		t.Run(tc.product, func(t *testing.T) {
			body := `{"requestId":"` + tc.requestID + `","requestParam":{"data":[{"name":"Msisdn","value":"255700000001"},{"name":"Type","value":"Payment"},{"name":"ChargeAmount","value":` + tc.amount + `},{"name":"Reason","value":"` + tc.reason + `"}]}}`
			e, err := ParseJSON(tc.product, []byte(body))
			if err != nil {
				t.Fatal(err)
			}
			if e.Payment.Status != tc.status || e.Payment.Channel != tc.channel || e.Payment.Stream != tc.stream {
				t.Fatalf("projection=%+v", e.Payment)
			}
		})
	}
}
