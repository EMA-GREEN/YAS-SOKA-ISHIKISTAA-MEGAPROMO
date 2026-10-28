package legacycontract

import (
	"encoding/json"
	"testing"
)

func TestPaymentStatusFromPHPBranches(t *testing.T) {
	tests := []struct {
		name, amount, reason, want string
	}{
		{"normal charge", `"300"`, "", "SUCCESS"},
		{"text zero follows PHP strict comparison", `"0"`, "", "SUCCESS"},
		{"numeric zero", `0`, "", "FAILED"},
		{"provider null marker", `"null"`, "", "FAILED"},
		{"empty amount", `""`, "", "FAILED"},
		{"reason overrides charge", `"300"`, "Low balance", "FAILED"},
		{"PHP empty reason marker", `"300"`, "0", "SUCCESS"},
		{"JSON null amount is unsafe", `null`, "", "UNKNOWN"},
		{"missing amount is unsafe", ``, "", "UNKNOWN"},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			got := Payment("soka", "S123", json.RawMessage(tc.amount), tc.reason)
			if got.Status != tc.want {
				t.Fatalf("status=%q; want %q", got.Status, tc.want)
			}
		})
	}
}

func TestPaymentRoutingFromPHPBranchOrder(t *testing.T) {
	tests := []struct {
		product, requestID, channel, stream string
	}{
		{"soka", "WW1", "WEB", "INTERACTION"},
		{"soka", "TS1", "RENEWAL_TEST", "RENEWAL"},
		{"soka", "A_RT1", "ANDROID", "INTERACTION"},
		{"soka", "RT1", "RETRY", "INTERACTION"},
		{"soka", "RP1", "RENEWAL", "INTERACTION"},
		{"soka", "W1", "RENEWAL", "RENEWAL"},
		{"soka", "w1", "RENEWAL", "RENEWAL"},
		{"soka", "S1", "SMS", "INTERACTION"},
		{"ishikistaa", "WW1", "WEB", "INTERACTION"},
		{"ishikistaa", "A_R1", "ANDROID", "INTERACTION"},
		{"ishikistaa", "GS1", "RENEWAL", "INTERACTION"},
		{"ishikistaa", "R1", "RENEWAL", "RENEWAL"},
		{"ishikistaa", "S1", "SMS", "INTERACTION"},
		{"megapromo", "WF1", "RENEWAL", "RENEWAL"},
		{"megapromo", "RMPR1", "RETRY", "INTERACTION"},
		{"megapromo", "S1", "SMS", "INTERACTION"},
	}
	for _, tc := range tests {
		t.Run(tc.product+"/"+tc.requestID, func(t *testing.T) {
			got := Payment(tc.product, tc.requestID, json.RawMessage(`"300"`), "")
			if got.Channel != tc.channel || got.Stream != tc.stream {
				t.Fatalf("route=%+v; want channel=%q stream=%q", got, tc.channel, tc.stream)
			}
		})
	}
}
