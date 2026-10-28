package relay

import (
	"testing"

	"yas-modernization/internal/obs"
)

func TestKindLabelIsBounded(t *testing.T) {
	m := NewMetrics(&obs.Registry{})
	m.published("soka", "Payment")
	m.published("soka", "attacker-chosen-value")
	m.failed("soka", "another-value")
	if m.Published.Value("soka", "Payment") != 1 || m.Published.Value("soka", "other") != 1 || m.Failed.Value("soka", "other") != 1 {
		t.Fatal("unknown kinds must collapse to other")
	}
	var none *Metrics
	none.published("soka", "Payment")
	none.failed("soka", "Payment")
	none.connected(true)
}
