// Package legacycontract records observable decisions in the PHP callback controllers.
// It is a characterization layer, not a replacement for the product workers.
package legacycontract

import (
	"bytes"
	"encoding/json"
	"strings"
)

type PaymentProjection struct {
	Channel string
	Stream  string
	Status  string
}

// Payment follows the branch order in SokaTrivia, Ishikistaa, and MegaPromo.
// Status is "UNKNOWN" when the source callback omits ChargeAmount; the PHP code
// reads an undefined key in that case, so treating it as a success is unsafe.
func Payment(product, requestID string, chargeAmount json.RawMessage, reason string) PaymentProjection {
	p := PaymentProjection{Channel: "SMS", Stream: "INTERACTION", Status: "UNKNOWN"}
	if len(chargeAmount) != 0 {
		charge := bytes.TrimSpace(chargeAmount)
		if bytes.Equal(charge, []byte(`null`)) {
			p.Status = "UNKNOWN"
		} else if bytes.Equal(charge, []byte(`"null"`)) || bytes.Equal(charge, []byte(`""`)) || bytes.Equal(charge, []byte(`0`)) {
			p.Status = "FAILED"
		} else if reason != "" && reason != "0" {
			p.Status = "FAILED"
		} else {
			p.Status = "SUCCESS"
		}
	}
	switch product {
	case "soka":
		switch {
		case strings.Contains(requestID, "WW"):
			p.Channel = "WEB"
		case strings.Contains(requestID, "TS"):
			p.Channel, p.Stream = "RENEWAL_TEST", "RENEWAL"
		case strings.Contains(requestID, "A"):
			p.Channel = "ANDROID"
		case strings.Contains(requestID, "RT"):
			p.Channel = "RETRY"
		case strings.Contains(requestID, "RP"):
			p.Channel = "RENEWAL"
		case strings.Contains(requestID, "W") || strings.Contains(requestID, "RW") || strings.Contains(requestID, "w"):
			p.Channel, p.Stream = "RENEWAL", "RENEWAL"
		}
	case "ishikistaa":
		switch {
		case strings.Contains(requestID, "WW"):
			p.Channel = "WEB"
		case strings.Contains(requestID, "A"):
			p.Channel = "ANDROID"
		case strings.Contains(requestID, "GS"):
			p.Channel = "RENEWAL"
		case strings.Contains(requestID, "R") || strings.Contains(requestID, "W"):
			p.Channel, p.Stream = "RENEWAL", "RENEWAL"
		}
	case "megapromo":
		switch {
		case strings.Contains(requestID, "W") || strings.Contains(requestID, "w"):
			p.Channel, p.Stream = "RENEWAL", "RENEWAL"
		case strings.Contains(requestID, "RMPR"):
			p.Channel = "RETRY"
		}
	}
	return p
}
