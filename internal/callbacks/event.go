package callbacks

import (
	"bytes"
	"crypto/sha256"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"strings"

	"yas-modernization/internal/legacycontract"
)

type Event struct {
	Product   string
	RequestID string
	Kind      string
	MSISDN    string
	Payload   []byte
	Hash      [32]byte
	Payment   legacycontract.PaymentProjection
}

type envelope struct {
	RequestID    string `json:"requestId"`
	Operation    string `json:"operation"`
	RequestParam struct {
		Data []struct {
			Name  string          `json:"name"`
			Value json.RawMessage `json:"value"`
		} `json:"data"`
	} `json:"requestParam"`
}

var ErrInvalid = errors.New("invalid callback")

// ParseJSON accepts the named-value envelope used by the three legacy billing routes.
// It does not infer a payment result; that remains product-specific worker behavior.
func ParseJSON(product string, body []byte) (Event, error) {
	if product != "soka" && product != "ishikistaa" && product != "megapromo" {
		return Event{}, fmt.Errorf("%w: product", ErrInvalid)
	}
	var env envelope
	dec := json.NewDecoder(bytes.NewReader(body))
	if err := dec.Decode(&env); err != nil {
		return Event{}, fmt.Errorf("%w: JSON", ErrInvalid)
	}
	if err := dec.Decode(new(any)); err != io.EOF { // second value must be EOF
		return Event{}, fmt.Errorf("%w: trailing JSON", ErrInvalid)
	}
	if len(env.RequestID) == 0 || len(env.RequestID) > 191 || len(env.RequestParam.Data) == 0 {
		return Event{}, fmt.Errorf("%w: required fields", ErrInvalid)
	}
	fields := map[string]string{}
	rawFields := map[string]json.RawMessage{}
	for _, item := range env.RequestParam.Data {
		if item.Name == "" {
			return Event{}, fmt.Errorf("%w: field name", ErrInvalid)
		}
		if _, duplicate := fields[item.Name]; duplicate {
			return Event{}, fmt.Errorf("%w: duplicate field", ErrInvalid)
		}
		var value string
		if err := json.Unmarshal(item.Value, &value); err != nil {
			if item.Name != "ChargeAmount" {
				return Event{}, fmt.Errorf("%w: field value", ErrInvalid)
			}
			var numeric json.Number
			if err := json.Unmarshal(item.Value, &numeric); err != nil || numeric == "" {
				return Event{}, fmt.Errorf("%w: field value", ErrInvalid)
			}
			value = numeric.String()
		}
		fields[item.Name] = value
		rawFields[item.Name] = item.Value
	}
	msisdn, kind := strings.TrimSpace(fields["Msisdn"]), fields["Type"]
	if msisdn == "" || len(msisdn) > 32 || kind == "" || len(kind) > 64 {
		return Event{}, fmt.Errorf("%w: subscriber or type", ErrInvalid)
	}
	// Hash canonical JSON so whitespace and member order do not change identity.
	var generic any
	if err := json.Unmarshal(body, &generic); err != nil {
		return Event{}, fmt.Errorf("%w: JSON", ErrInvalid)
	}
	canonical, err := json.Marshal(generic)
	if err != nil {
		return Event{}, err
	}
	event := Event{Product: product, RequestID: env.RequestID, Kind: kind, MSISDN: msisdn, Payload: canonical, Hash: sha256.Sum256(canonical)}
	if kind == "Payment" {
		event.Payment = legacycontract.Payment(product, env.RequestID, rawFields["ChargeAmount"], fields["Reason"])
	}
	return event, nil
}
