package callbacks

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"
)

// Event kinds for callbacks outside the CP_NOTIFICATION envelope.
const (
	KindMpushSubsInit  = "MpushSubsInit"
	KindTigoPesaResult = "TigoPesaPushResult"
)

var mpushChannels = map[string]bool{"SMS": true, "USSD": true, "APP": true}

// mpushStatus is the legacy M-Push reply. The PHP controllers answer every
// request with HTTP 200 and signal errors in the body, so this does the same.
type mpushStatus struct {
	Status      string `json:"status"`
	StatusCode  string `json:"statusCode"`
	Description string `json:"description"`
}

// ParseMpush accepts the M-Push subscription-init request. The provider sends
// no request ID, so identity is the request content within one Dar es Salaam
// calendar day: an identical retry the same day is stored once, while the same
// subscriber asking again on a later day is a new request. MegaPromo's PHP
// controller ignores ussd_data; it is kept in the payload but not in identity
// for that product.
func ParseMpush(product string, body []byte, now time.Time) (Event, *mpushStatus, error) {
	if product != "soka" && product != "ishikistaa" && product != "megapromo" {
		return Event{}, nil, fmt.Errorf("%w: product", ErrInvalid)
	}
	if len(body) == 0 {
		return Event{}, &mpushStatus{"1", "1001", "Empty Request"}, ErrInvalid
	}
	var raw map[string]any
	dec := json.NewDecoder(strings.NewReader(string(body)))
	if err := dec.Decode(&raw); err != nil || dec.Decode(new(any)) != io.EOF {
		return Event{}, &mpushStatus{"1", "1002", "Invalid JSON"}, ErrInvalid
	}
	field := func(lower, upper string) string {
		for _, key := range []string{lower, upper} {
			if v, ok := raw[key]; ok {
				if s, ok := v.(string); ok {
					return s
				}
			}
		}
		return ""
	}
	msisdn := strings.TrimSpace(field("msisdn", "MSISDN"))
	channel := strings.ToUpper(strings.TrimSpace(field("channel", "CHANNEL")))
	ussd := field("ussd_data", "USSD_DATA")
	if msisdn == "" || channel == "" || len(msisdn) > 32 {
		return Event{}, &mpushStatus{"1", "1003", "msisdn and channel are required"}, ErrInvalid
	}
	if !mpushChannels[channel] {
		return Event{}, &mpushStatus{"1", "1004", "channel must be one of SMS, USSD, APP"}, ErrInvalid
	}
	if product == "megapromo" {
		ussd = ""
	}
	normalized, err := json.Marshal(map[string]string{"msisdn": msisdn, "channel": channel, "ussd_data": ussd})
	if err != nil {
		return Event{}, nil, err
	}
	day := now.In(darEsSalaam()).Format("20060102")
	digest := sha256.Sum256([]byte(product + "\x00" + day + "\x00" + string(normalized)))
	return Event{
		Product:   product,
		RequestID: "mpush:" + day + ":" + hex.EncodeToString(digest[:16]),
		Kind:      KindMpushSubsInit,
		MSISDN:    msisdn,
		Payload:   normalized,
		Hash:      sha256.Sum256(normalized),
	}, nil, nil
}

// ParseTigoPesaPush accepts the MegaPromo TigoPesa/Mixx push result. The
// provider's ReferenceID identifies the push transaction.
func ParseTigoPesaPush(body []byte) (Event, error) {
	var raw map[string]json.RawMessage
	dec := json.NewDecoder(strings.NewReader(string(body)))
	if err := dec.Decode(&raw); err != nil || dec.Decode(new(any)) != io.EOF {
		return Event{}, fmt.Errorf("%w: JSON", ErrInvalid)
	}
	var ref string
	if err := json.Unmarshal(raw["ReferenceID"], &ref); err != nil || ref == "" || len(ref) > 191 {
		return Event{}, fmt.Errorf("%w: ReferenceID", ErrInvalid)
	}
	if !validPushStatus(raw["Status"]) {
		return Event{}, fmt.Errorf("%w: Status", ErrInvalid)
	}
	var generic any
	if err := json.Unmarshal(body, &generic); err != nil {
		return Event{}, fmt.Errorf("%w: JSON", ErrInvalid)
	}
	canonical, err := json.Marshal(generic)
	if err != nil {
		return Event{}, err
	}
	// The push callback does not carry the MSISDN; workers read it from the
	// push transaction that ReferenceID names.
	return Event{Product: "megapromo", RequestID: ref, Kind: KindTigoPesaResult, Payload: canonical, Hash: sha256.Sum256(canonical)}, nil
}

// validPushStatus accepts the JSON boolean and its string forms. PHP tested the
// value for truthiness; anything else is rejected rather than guessed, because
// it decides whether tickets are credited.
func validPushStatus(raw json.RawMessage) bool {
	var b bool
	if json.Unmarshal(raw, &b) == nil {
		return true
	}
	var text string
	if json.Unmarshal(raw, &text) != nil {
		return false
	}
	text = strings.ToLower(strings.TrimSpace(text))
	return text == "true" || text == "false"
}

func darEsSalaam() *time.Location {
	if loc, err := time.LoadLocation("Africa/Dar_es_Salaam"); err == nil {
		return loc
	}
	return time.FixedZone("EAT", 3*60*60)
}

func (h Handler) receiveMpush(w http.ResponseWriter, r *http.Request, product string) {
	started := time.Now()
	defer r.Body.Close()
	body, err := io.ReadAll(http.MaxBytesReader(w, r.Body, 64<<10))
	if err != nil {
		h.Metrics.observe(product, "too_large", started)
		http.Error(w, "invalid request size", http.StatusRequestEntityTooLarge)
		return
	}
	event, reply, err := ParseMpush(product, body, time.Now())
	if err != nil {
		h.Metrics.observe(product, "invalid", started)
		if reply == nil {
			http.Error(w, "invalid callback", http.StatusBadRequest)
			return
		}
		writeJSON(w, http.StatusOK, reply)
		return
	}
	if !h.save(w, r, product, event, started) {
		return
	}
	writeJSON(w, http.StatusOK, mpushStatus{"0", "0000", "Success"})
	h.Metrics.observe(product, "accepted", started)
}

func (h Handler) receiveTigoPesaPush(w http.ResponseWriter, r *http.Request) {
	const product = "megapromo"
	started := time.Now()
	defer r.Body.Close()
	body, err := io.ReadAll(http.MaxBytesReader(w, r.Body, 64<<10))
	if err != nil {
		h.Metrics.observe(product, "too_large", started)
		http.Error(w, "invalid request size", http.StatusRequestEntityTooLarge)
		return
	}
	event, err := ParseTigoPesaPush(body)
	if err != nil {
		h.Metrics.observe(product, "invalid", started)
		http.Error(w, "invalid callback", http.StatusBadRequest)
		return
	}
	// Unlike PHP, success is only sent once the result is stored, so a database
	// failure makes the provider retry instead of losing the payment result.
	if !h.save(w, r, product, event, started) {
		return
	}
	writeJSON(w, http.StatusOK, struct {
		ResponseCode        string `json:"ResponseCode"`
		ResponseStatus      bool   `json:"ResponseStatus"`
		ResponseDescription string `json:"ResponseDescription"`
		ReferenceID         string `json:"ReferenceID"`
	}{"BILLER-30-0000-S", true, "Callback successful", event.RequestID})
	h.Metrics.observe(product, "accepted", started)
}

// save stores the event and writes the error response when it fails.
func (h Handler) save(w http.ResponseWriter, r *http.Request, product string, event Event, started time.Time) bool {
	ctx, cancel := context.WithTimeout(r.Context(), 5*time.Second)
	defer cancel()
	_, err := h.Store.Save(ctx, event)
	if errors.Is(err, ErrConflict) {
		h.Log.Warn("callback request ID reused with different payload", "product", product, "kind", event.Kind, "request_id", event.RequestID)
		h.Metrics.observe(product, "conflict", started)
		http.Error(w, "conflicting callback", http.StatusConflict)
		return false
	}
	if err != nil {
		h.Log.Error("callback persistence failed", "product", product, "kind", event.Kind, "error", err)
		h.Metrics.observe(product, "unavailable", started)
		http.Error(w, "temporarily unavailable", http.StatusServiceUnavailable)
		return false
	}
	return true
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}
