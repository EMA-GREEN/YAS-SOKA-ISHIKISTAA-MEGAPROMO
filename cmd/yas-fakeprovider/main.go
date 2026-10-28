// Command yas-fakeprovider stands in for the Tigo APIs in test stacks. It
// answers the charge, InteractiveRequest SMS, ACTIVATE, renewal, and login
// calls the Go dispatchers make, records every request, and sends the
// asynchronous payment and activation callbacks a real provider would.
//
// Outcomes are chosen by the MSISDN's last digit so tests can drive each path:
//
//	…1  charge: low balance at 99 TZS, success at any other amount
//	…2  charge: always low balance
//	…3  charge: billing error
//	…4  SMS: the first send (request ID "I…") is refused; the resend is accepted
//	other  success
//
// It must never be pointed at real customers: it only listens on loopback
// unless FAKE_ALLOW_REMOTE=true, for container networks.
package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"strings"
	"sync"
	"time"
)

type record struct {
	Kind      string            `json:"kind"`
	RequestID string            `json:"requestId"`
	MSISDN    string            `json:"msisdn"`
	Fields    map[string]string `json:"fields,omitempty"`
	Header    string            `json:"authorization,omitempty"`
	Status    string            `json:"status"`
	At        time.Time         `json:"at"`
}

type provider struct {
	mu            sync.Mutex
	records       []record
	callbackBase  string
	callbackToken string
	delay         time.Duration
	client        *http.Client
}

// Product callback routes by offer code, as the gateways are configured.
var productByOffer = map[string]string{
	"017008600005": "soka", "017008600272": "soka", "017008600010": "soka",
	"017008600007": "ishikistaa", "017008600009": "ishikistaa",
}

var routes = map[string]string{"soka": "/soka-trivia/notification", "ishikistaa": "/ishikistaa/notification"}

func main() {
	listen := envOr("FAKE_LISTEN", "127.0.0.1:18090")
	host, _, err := net.SplitHostPort(listen)
	if err != nil {
		log.Fatal("FAKE_LISTEN must be host:port")
	}
	if ip := net.ParseIP(host); os.Getenv("FAKE_ALLOW_REMOTE") != "true" && host != "localhost" && (ip == nil || !ip.IsLoopback()) {
		log.Fatal("refusing to listen beyond loopback without FAKE_ALLOW_REMOTE=true")
	}
	p := &provider{
		callbackBase:  envOr("FAKE_CALLBACK_BASE", "http://127.0.0.1:18080"),
		callbackToken: os.Getenv("FAKE_CALLBACK_TOKEN"),
		delay:         300 * time.Millisecond,
		client:        &http.Client{Timeout: 5 * time.Second},
	}
	mux := http.NewServeMux()
	mux.HandleFunc("POST /api/auth/login", func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, map[string]string{"token": "fake-provider-token", "refreshToken": "fake-refresh"})
	})
	mux.HandleFunc("POST /charge", p.charge)
	mux.HandleFunc("POST /sms", p.sms)
	mux.HandleFunc("POST /activate", p.activate)
	mux.HandleFunc("POST /renewal/{target}", p.renewal)
	mux.HandleFunc("GET /requests", p.list)
	mux.HandleFunc("POST /reset", func(w http.ResponseWriter, _ *http.Request) {
		p.mu.Lock()
		p.records = nil
		p.mu.Unlock()
		w.WriteHeader(http.StatusNoContent)
	})
	mux.HandleFunc("GET /healthz", func(w http.ResponseWriter, _ *http.Request) { w.WriteHeader(http.StatusNoContent) })
	log.Printf("fake provider listening on %s", listen)
	server := &http.Server{Addr: listen, Handler: mux, ReadHeaderTimeout: 5 * time.Second}
	log.Fatal(server.ListenAndServe())
}

// envelope reads the legacy {requestId, requestParam.data[]} body.
func envelope(r *http.Request) (string, string, map[string]string, error) {
	var body struct {
		RequestID    string `json:"requestId"`
		Operation    string `json:"operation"`
		RequestParam struct {
			Data []struct{ Name, Value string } `json:"data"`
		} `json:"requestParam"`
	}
	if err := json.NewDecoder(io.LimitReader(r.Body, 64<<10)).Decode(&body); err != nil {
		return "", "", nil, err
	}
	fields := map[string]string{}
	for _, f := range body.RequestParam.Data {
		fields[f.Name] = f.Value
	}
	return body.RequestID, body.Operation, fields, nil
}

func (p *provider) add(rec record) {
	rec.At = time.Now().UTC()
	p.mu.Lock()
	p.records = append(p.records, rec)
	p.mu.Unlock()
}

func (p *provider) charge(w http.ResponseWriter, r *http.Request) {
	requestID, _, fields, err := envelope(r)
	if err != nil {
		http.Error(w, "bad request", http.StatusBadRequest)
		return
	}
	msisdn, amount, offer := fields["Msisdn"], fields["ChargeAmount"], fields["OfferCode"]
	outcome := "SUCCESS"
	switch last(msisdn) {
	case '1':
		if amount == "99" {
			outcome = "LOW_BALANCE"
		}
	case '2':
		outcome = "LOW_BALANCE"
	case '3':
		outcome = "BILLING_ERROR"
	}
	p.add(record{Kind: "charge", RequestID: requestID, MSISDN: msisdn, Fields: fields, Header: r.Header.Get("Authorization"), Status: outcome})
	writeJSON(w, map[string]any{"responseParam": map[string]string{"status": "0", "statusCode": "0000"}})
	data := []map[string]string{{"name": "Msisdn", "value": msisdn}, {"name": "Type", "value": "Payment"}, {"name": "ChargeAmount", "value": amount}}
	switch outcome {
	case "SUCCESS":
		data = append(data, map[string]string{"name": "BillingId", "value": "FAKE-" + requestID})
	case "LOW_BALANCE":
		data = append(data, map[string]string{"name": "Reason", "value": "Low balance"})
	default:
		data = append(data, map[string]string{"name": "Reason", "value": "Subscriber barred"})
	}
	go p.callback(productByOffer[offer], map[string]any{"requestId": requestID, "requestParam": map[string]any{"data": data}})
}

func (p *provider) sms(w http.ResponseWriter, r *http.Request) {
	requestID, _, fields, err := envelope(r)
	if err != nil {
		http.Error(w, "bad request", http.StatusBadRequest)
		return
	}
	msisdn := fields["Msisdn"]
	status := "0"
	if last(msisdn) == '4' && !strings.HasPrefix(requestID, "IR") {
		status = "1"
	}
	p.add(record{Kind: "sms", RequestID: requestID, MSISDN: msisdn, Fields: fields, Header: r.Header.Get("Authorization"), Status: status})
	writeJSON(w, map[string]any{"responseParam": map[string]string{"status": status, "statusCode": map[string]string{"0": "0000", "1": "1001"}[status]}})
}

func (p *provider) activate(w http.ResponseWriter, r *http.Request) {
	requestID, _, fields, err := envelope(r)
	if err != nil {
		http.Error(w, "bad request", http.StatusBadRequest)
		return
	}
	msisdn := fields["Msisdn"]
	p.add(record{Kind: "activate", RequestID: requestID, MSISDN: msisdn, Fields: fields, Header: r.Header.Get("X-Authorization"), Status: "0"})
	writeJSON(w, map[string]any{"responseParam": map[string]string{"status": "0", "statusCode": "0000"}})
	go p.callback(productByOffer[fields["OfferCode"]], map[string]any{"requestId": "ACT-" + requestID, "requestParam": map[string]any{"data": []map[string]string{
		{"name": "Msisdn", "value": msisdn}, {"name": "Type", "value": "Activation"}, {"name": "SubscriptionStatus", "value": "A"},
	}}})
}

func (p *provider) renewal(w http.ResponseWriter, r *http.Request) {
	var body map[string]string
	_ = json.NewDecoder(io.LimitReader(r.Body, 16<<10)).Decode(&body)
	p.add(record{Kind: "renewal-" + r.PathValue("target"), MSISDN: body["msisdn"], Fields: body, Status: "200"})
	w.Write([]byte("ok"))
}

func (p *provider) list(w http.ResponseWriter, r *http.Request) {
	kind, msisdn := r.URL.Query().Get("kind"), r.URL.Query().Get("msisdn")
	p.mu.Lock()
	out := []record{}
	for _, rec := range p.records {
		if (kind == "" || rec.Kind == kind) && (msisdn == "" || rec.MSISDN == msisdn) {
			out = append(out, rec)
		}
	}
	p.mu.Unlock()
	writeJSON(w, out)
}

// callback posts the asynchronous provider notification back to the stack,
// retrying briefly as a provider would on 5xx.
func (p *provider) callback(product string, body map[string]any) {
	route, ok := routes[product]
	if !ok {
		log.Printf("no callback route for product %q", product)
		return
	}
	time.Sleep(p.delay)
	raw, _ := json.Marshal(body)
	for attempt := 0; attempt < 5; attempt++ {
		req, _ := http.NewRequest(http.MethodPost, p.callbackBase+route, bytes.NewReader(raw))
		req.Header.Set("Content-Type", "application/json")
		if p.callbackToken != "" {
			req.Header.Set("X-Callback-Token", p.callbackToken)
		}
		resp, err := p.client.Do(req)
		if err == nil {
			resp.Body.Close()
			if resp.StatusCode < 500 {
				if resp.StatusCode != http.StatusOK {
					log.Printf("callback %s answered %d", route, resp.StatusCode)
				}
				return
			}
		}
		time.Sleep(time.Second)
	}
	log.Printf("callback to %s failed after retries", route)
}

func last(s string) byte {
	if s == "" {
		return 0
	}
	return s[len(s)-1]
}

func writeJSON(w http.ResponseWriter, v any) {
	w.Header().Set("Content-Type", "application/json")
	if err := json.NewEncoder(w).Encode(v); err != nil {
		fmt.Fprintln(os.Stderr, err)
	}
}

func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}
