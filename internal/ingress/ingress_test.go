package ingress

import (
	"bytes"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"

	"yas-modernization/internal/obs"
)

const token = "0123456789abcdef0123456789abcdef"

func env(values map[string]string) func(string) string {
	return func(key string) string { return values[key] }
}

func TestFromEnvFailsClosed(t *testing.T) {
	for name, values := range map[string]map[string]string{
		"no policy":        {},
		"report no policy": {"INGRESS_AUTH_MODE": "report"},
		"unknown mode":     {"INGRESS_AUTH_MODE": "permissive", "INGRESS_TOKEN": token},
		"short token":      {"INGRESS_TOKEN": "short"},
		"bad CIDR":         {"INGRESS_ALLOWED_CIDRS": "10.0.0.0/33"},
		"bad address":      {"INGRESS_ALLOWED_CIDRS": "provider.example"},
		"bad proxy":        {"INGRESS_TOKEN": token, "INGRESS_TRUSTED_PROXY_CIDRS": "nope"},
		"zero in flight":   {"INGRESS_TOKEN": token, "INGRESS_MAX_INFLIGHT": "0"},
	} {
		if _, err := FromEnv(env(values)); err == nil {
			t.Errorf("%s: expected error", name)
		} else if strings.Contains(err.Error(), token) {
			t.Errorf("%s: error reveals token", name)
		}
	}
	cfg, err := FromEnv(env(map[string]string{"INGRESS_AUTH_MODE": "off"}))
	if err != nil || cfg.Mode != Off {
		t.Fatalf("explicit off: %v %v", cfg.Mode, err)
	}
	cfg, err = FromEnv(env(map[string]string{"INGRESS_ALLOWED_CIDRS": " 196.0.0.1, 10.1.0.0/16 ,::ffff:172.16.0.9"}))
	if err != nil || cfg.Mode != Enforce || len(cfg.Allowed) != 3 || cfg.HasToken() {
		t.Fatalf("allowlist: %+v %v", cfg, err)
	}
}

type fixture struct {
	guard   *Guard
	handler http.Handler
	logs    *bytes.Buffer
	reached int
}

func newFixture(t *testing.T, values map[string]string) *fixture {
	t.Helper()
	cfg, err := FromEnv(env(values))
	if err != nil {
		t.Fatal(err)
	}
	f := &fixture{logs: &bytes.Buffer{}}
	f.guard = NewGuard(cfg, obs.NewLogger(f.logs), &obs.Registry{})
	f.handler = f.guard.Wrap(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		f.reached++
		w.WriteHeader(http.StatusOK)
	}))
	return f
}

func (f *fixture) do(remote string, headers map[string]string) int {
	r := httptest.NewRequest(http.MethodPost, "/soka-trivia/notification", strings.NewReader("{}"))
	r.RemoteAddr = remote
	for k, v := range headers {
		r.Header.Add(k, v)
	}
	w := httptest.NewRecorder()
	f.handler.ServeHTTP(w, r)
	return w.Code
}

func TestAllowlist(t *testing.T) {
	f := newFixture(t, map[string]string{"INGRESS_ALLOWED_CIDRS": "196.0.0.0/24,2001:db8::/32"})
	for remote, want := range map[string]int{
		"196.0.0.7:5000":          http.StatusOK,
		"[::ffff:196.0.0.8]:5000": http.StatusOK,
		"[2001:db8::1]:443":       http.StatusOK,
		"196.0.1.7:5000":          http.StatusForbidden,
		"8.8.8.8:5000":            http.StatusForbidden,
		"garbage":                 http.StatusForbidden,
	} {
		if got := f.do(remote, nil); got != want {
			t.Errorf("%s: status %d, want %d", remote, got, want)
		}
	}
	if f.reached != 3 {
		t.Fatalf("handler reached %d times", f.reached)
	}
	if f.guard.Denied.Value("source_not_allowed", "enforce") != 2 || f.guard.Denied.Value("bad_client_address", "enforce") != 1 {
		t.Fatal("denials not counted by reason")
	}
}

func TestForwardedForOnlyFromTrustedProxy(t *testing.T) {
	f := newFixture(t, map[string]string{"INGRESS_ALLOWED_CIDRS": "196.0.0.0/24", "INGRESS_TRUSTED_PROXY_CIDRS": "10.0.0.0/8"})
	cases := []struct {
		name, remote, xff string
		want              int
	}{
		{"untrusted peer cannot claim an allowed address", "8.8.8.8:1", "196.0.0.5", http.StatusForbidden},
		{"trusted proxy forwards allowed client", "10.0.0.2:1", "196.0.0.5", http.StatusOK},
		{"forged leftmost entry is ignored", "10.0.0.2:1", "196.0.0.5, 8.8.8.8", http.StatusForbidden},
		{"chain of trusted proxies", "10.0.0.2:1", "196.0.0.5, 10.9.9.9", http.StatusOK},
		{"unparsable hop", "10.0.0.2:1", "196.0.0.5, not-an-ip", http.StatusForbidden},
		{"proxy without header is judged itself", "10.0.0.2:1", "", http.StatusForbidden},
	}
	for _, c := range cases {
		headers := map[string]string{}
		if c.xff != "" {
			headers["X-Forwarded-For"] = c.xff
		}
		if got := f.do(c.remote, headers); got != c.want {
			t.Errorf("%s: status %d, want %d", c.name, got, c.want)
		}
	}
}

func TestToken(t *testing.T) {
	f := newFixture(t, map[string]string{"INGRESS_TOKEN": token})
	for name, c := range map[string]struct {
		headers map[string]string
		want    int
	}{
		"missing":       {nil, http.StatusForbidden},
		"wrong":         {map[string]string{TokenHeader: strings.ToUpper(token)}, http.StatusForbidden},
		"prefix":        {map[string]string{TokenHeader: token[:31]}, http.StatusForbidden},
		"header":        {map[string]string{TokenHeader: token}, http.StatusOK},
		"bearer":        {map[string]string{"Authorization": "Bearer " + token}, http.StatusOK},
		"basic ignored": {map[string]string{"Authorization": "Basic " + token}, http.StatusForbidden},
	} {
		if got := f.do("8.8.8.8:1", c.headers); got != c.want {
			t.Errorf("%s: status %d, want %d", name, got, c.want)
		}
	}
	if strings.Contains(f.logs.String(), token[:16]) {
		t.Fatal("log contains token material")
	}
}

func TestAllowlistAndTokenBothRequired(t *testing.T) {
	f := newFixture(t, map[string]string{"INGRESS_ALLOWED_CIDRS": "196.0.0.0/24", "INGRESS_TOKEN": token})
	if f.do("196.0.0.1:1", nil) != http.StatusForbidden || f.do("8.8.8.8:1", map[string]string{TokenHeader: token}) != http.StatusForbidden {
		t.Fatal("one factor alone was accepted")
	}
	if f.do("196.0.0.1:1", map[string]string{TokenHeader: token}) != http.StatusOK {
		t.Fatal("both factors rejected")
	}
}

func TestReportModeAdmitsButRecords(t *testing.T) {
	f := newFixture(t, map[string]string{"INGRESS_AUTH_MODE": "report", "INGRESS_ALLOWED_CIDRS": "196.0.0.0/24"})
	if got := f.do("8.8.8.8:1", nil); got != http.StatusOK || f.reached != 1 {
		t.Fatalf("report mode status %d reached %d", got, f.reached)
	}
	if f.guard.Denied.Value("source_not_allowed", "report") != 1 || !strings.Contains(f.logs.String(), "8.8.8.8") {
		t.Fatal("report mode did not record the would-be denial")
	}
}

func TestOverloadSheds(t *testing.T) {
	cfg, err := FromEnv(env(map[string]string{"INGRESS_AUTH_MODE": "off", "INGRESS_MAX_INFLIGHT": "2"}))
	if err != nil {
		t.Fatal(err)
	}
	guard := NewGuard(cfg, slog.New(slog.NewTextHandler(io.Discard, nil)), &obs.Registry{})
	release, entered := make(chan struct{}), make(chan struct{}, 2)
	h := guard.Wrap(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		entered <- struct{}{}
		<-release
	}))
	var wg sync.WaitGroup
	for range 2 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			h.ServeHTTP(httptest.NewRecorder(), httptest.NewRequest(http.MethodPost, "/", nil))
		}()
	}
	<-entered
	<-entered
	w := httptest.NewRecorder()
	h.ServeHTTP(w, httptest.NewRequest(http.MethodPost, "/", nil))
	close(release)
	wg.Wait()
	if w.Code != http.StatusServiceUnavailable || w.Header().Get("Retry-After") == "" || guard.Overload.Value() != 1 {
		t.Fatalf("overload status=%d retry=%q count=%v", w.Code, w.Header().Get("Retry-After"), guard.Overload.Value())
	}
}
