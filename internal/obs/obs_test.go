package obs

import (
	"bytes"
	"context"
	"errors"
	"io"
	"net"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestRedact(t *testing.T) {
	for in, want := range map[string]string{
		"subscriber 255700000001 failed":                 "subscriber [redacted] failed",
		"dial root:s3cret@tcp(db:3306)/yas":              "dial [redacted]@tcp(db:3306)/yas",
		"amqp://guest:guest@broker:5672/ refused":        "amqp://[redacted]@broker:5672/ refused",
		"Error 1062: Duplicate entry 'PU12255700000001'": "Error 1062: Duplicate entry 'PU[redacted]'",
		"event 42 retry 3":                               "event 42 retry 3",
	} {
		if got := Redact(in); got != want {
			t.Errorf("Redact(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestLoggerRedacts(t *testing.T) {
	var buf bytes.Buffer
	log := NewLogger(&buf)
	log.Error("failure",
		"msisdn", "255700000001", "payload", `{"x":1}`, "DSN", "anything", "token", "abc",
		"error", errors.New("Error 1062 for 255711111111 via user:pw@tcp(h)"),
		"note", "client 255722222222", "event_id", int64(255733333333))
	out := buf.String()
	for _, leak := range []string{"255700000001", `{\"x\":1}`, "anything", "abc", "255711111111", "user:pw", "255722222222"} {
		if strings.Contains(out, leak) {
			t.Errorf("log leaked %q: %s", leak, out)
		}
	}
	if !strings.Contains(out, `"event_id":255733333333`) {
		t.Errorf("numeric IDs should be kept: %s", out)
	}
}

func TestMetricsExposition(t *testing.T) {
	reg := &Registry{}
	c := reg.Counter("yas_test_total", "Test counter.", "product", "outcome")
	c.Inc("soka", "accepted")
	c.Inc("soka", "accepted")
	c.Inc("ishikistaa", "invalid")
	h := reg.Histogram("yas_test_seconds", "Test histogram.", []float64{0.1, 1}, "product")
	h.Observe(0.05, "soka")
	h.Observe(0.5, "soka")
	reg.GaugeFunc("yas_test_backlog", "Test gauge.", func(context.Context) (float64, error) { return 7, nil })
	reg.GaugeFunc("yas_test_broken", "Unreadable gauge.", func(context.Context) (float64, error) { return 0, errors.New("db down") })
	w := httptest.NewRecorder()
	reg.Handler().ServeHTTP(w, httptest.NewRequest("GET", "/metrics", nil))
	out := w.Body.String()
	for _, want := range []string{
		"# TYPE yas_test_total counter",
		`yas_test_total{product="soka",outcome="accepted"} 2`,
		`yas_test_total{product="ishikistaa",outcome="invalid"} 1`,
		`yas_test_seconds_bucket{product="soka",le="0.1"} 1`,
		`yas_test_seconds_bucket{product="soka",le="1"} 2`,
		`yas_test_seconds_bucket{product="soka",le="+Inf"} 2`,
		`yas_test_seconds_count{product="soka"} 2`,
		"yas_test_backlog 7",
		"# TYPE yas_test_broken gauge",
	} {
		if !strings.Contains(out, want) {
			t.Errorf("missing %q in:\n%s", want, out)
		}
	}
	if strings.Contains(out, "yas_test_broken 0") {
		t.Error("failed gauge read must not report zero")
	}
}

func TestGaugesAndStates(t *testing.T) {
	reg := &Registry{}
	g := reg.Gauge("yas_test_connected", "Test gauge.", "consumer")
	g.Set(1, "mo")
	reg.StateGaugeFunc("yas_test_states", "Test states.", "state", []string{"PENDING", "NEEDS_REVIEW"},
		func(context.Context) (map[string]float64, error) { return map[string]float64{"PENDING": 3}, nil })
	reg.StateGaugeFunc("yas_test_states_broken", "Unreadable.", "state", []string{"PENDING"},
		func(context.Context) (map[string]float64, error) { return nil, errors.New("down") })
	w := httptest.NewRecorder()
	reg.Handler().ServeHTTP(w, httptest.NewRequest("GET", "/metrics", nil))
	out := w.Body.String()
	for _, want := range []string{`yas_test_connected{consumer="mo"} 1`, `yas_test_states{state="PENDING"} 3`, `yas_test_states{state="NEEDS_REVIEW"} 0`} {
		if !strings.Contains(out, want) {
			t.Errorf("missing %q in:\n%s", want, out)
		}
	}
	if strings.Contains(out, `yas_test_states_broken{`) {
		t.Error("failed state read must omit samples")
	}
}

func TestServeOps(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	reg := &Registry{}
	reg.Counter("yas_test_total", "Test.").Inc()
	ready := errors.New("not ready")
	if err := ServeOps(ctx, "127.0.0.1:0", reg, func(context.Context) error { return ready }, NewLogger(io.Discard)); err != nil {
		t.Fatal(err)
	}
	// A taken port must fail at startup.
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	if err := ServeOps(ctx, listener.Addr().String(), reg, nil, NewLogger(io.Discard)); err == nil {
		t.Fatal("expected bind failure")
	}
}

func TestOpsAddr(t *testing.T) {
	t.Setenv("OPS_ADDR", "")
	if got := OpsAddr("9093"); got != "127.0.0.1:9093" {
		t.Fatal(got)
	}
	t.Setenv("OPS_ADDR", "10.0.0.5:9100")
	if got := OpsAddr("9093"); got != "10.0.0.5:9100" {
		t.Fatal(got)
	}
}
