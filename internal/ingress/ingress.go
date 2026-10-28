// Package ingress decides which callback requests reach the handlers. The PHP
// gateway accepted callbacks from anyone, so the Go server refuses to start
// without an explicit policy.
package ingress

import (
	"crypto/sha256"
	"crypto/subtle"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"net/netip"
	"strconv"
	"strings"

	"yas-modernization/internal/obs"
)

type Mode string

const (
	// Enforce rejects requests that fail the policy.
	Enforce Mode = "enforce"
	// Report admits every request but logs and counts those that would fail,
	// so a policy can be checked against real provider traffic before enforcing.
	Report Mode = "report"
	// Off admits every request; it must be chosen explicitly.
	Off Mode = "off"
)

const TokenHeader = "X-Callback-Token"

// Config is read from the environment by FromEnv.
type Config struct {
	Mode           Mode
	Allowed        []netip.Prefix
	TrustedProxies []netip.Prefix
	tokenDigest    *[32]byte
	MaxInFlight    int
}

// FromEnv reads INGRESS_AUTH_MODE, INGRESS_ALLOWED_CIDRS, INGRESS_TRUSTED_PROXY_CIDRS,
// INGRESS_TOKEN, and INGRESS_MAX_INFLIGHT. Errors never include the token.
func FromEnv(getenv func(string) string) (Config, error) {
	cfg := Config{Mode: Mode(strings.ToLower(strings.TrimSpace(getenv("INGRESS_AUTH_MODE")))), MaxInFlight: 64}
	if cfg.Mode == "" {
		cfg.Mode = Enforce
	}
	if cfg.Mode != Enforce && cfg.Mode != Report && cfg.Mode != Off {
		return Config{}, fmt.Errorf("INGRESS_AUTH_MODE must be enforce, report, or off")
	}
	var err error
	if cfg.Allowed, err = parsePrefixes(getenv("INGRESS_ALLOWED_CIDRS")); err != nil {
		return Config{}, fmt.Errorf("INGRESS_ALLOWED_CIDRS: %w", err)
	}
	if cfg.TrustedProxies, err = parsePrefixes(getenv("INGRESS_TRUSTED_PROXY_CIDRS")); err != nil {
		return Config{}, fmt.Errorf("INGRESS_TRUSTED_PROXY_CIDRS: %w", err)
	}
	if token := getenv("INGRESS_TOKEN"); token != "" {
		if len(token) < 32 {
			return Config{}, errors.New("INGRESS_TOKEN must be at least 32 characters")
		}
		digest := sha256.Sum256([]byte(token))
		cfg.tokenDigest = &digest
	}
	if raw := getenv("INGRESS_MAX_INFLIGHT"); raw != "" {
		if cfg.MaxInFlight, err = strconv.Atoi(raw); err != nil || cfg.MaxInFlight < 1 {
			return Config{}, errors.New("INGRESS_MAX_INFLIGHT must be a positive integer")
		}
	}
	if cfg.Mode != Off && len(cfg.Allowed) == 0 && cfg.tokenDigest == nil {
		return Config{}, errors.New("set INGRESS_ALLOWED_CIDRS and/or INGRESS_TOKEN, or INGRESS_AUTH_MODE=off explicitly")
	}
	return cfg, nil
}

func parsePrefixes(raw string) ([]netip.Prefix, error) {
	var out []netip.Prefix
	for _, item := range strings.Split(raw, ",") {
		item = strings.TrimSpace(item)
		if item == "" {
			continue
		}
		if !strings.Contains(item, "/") {
			addr, err := netip.ParseAddr(item)
			if err != nil {
				return nil, fmt.Errorf("invalid address %q", item)
			}
			out = append(out, netip.PrefixFrom(addr.Unmap(), addr.Unmap().BitLen()))
			continue
		}
		prefix, err := netip.ParsePrefix(item)
		if err != nil {
			return nil, fmt.Errorf("invalid CIDR %q", item)
		}
		out = append(out, prefix.Masked())
	}
	return out, nil
}

func (c Config) HasToken() bool { return c.tokenDigest != nil }

// Guard wraps callback routes. Health paths are left to the caller's mux.
type Guard struct {
	Config   Config
	Log      *slog.Logger
	Denied   *obs.CounterVec // labels: reason, mode
	Overload *obs.CounterVec // no labels
}

func NewGuard(cfg Config, log *slog.Logger, reg *obs.Registry) *Guard {
	return &Guard{
		Config:   cfg,
		Log:      log,
		Denied:   reg.Counter("yas_ingress_denied_total", "Callback requests failing the ingress policy.", "reason", "mode"),
		Overload: reg.Counter("yas_ingress_overloaded_total", "Callback requests refused because too many were in flight."),
	}
}

func (g *Guard) Wrap(next http.Handler) http.Handler {
	slots := make(chan struct{}, g.Config.MaxInFlight)
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if g.Config.Mode != Off {
			client, reason := g.check(r)
			if reason != "" {
				g.Denied.Inc(reason, string(g.Config.Mode))
				g.Log.Warn("callback failed ingress policy", "reason", reason, "mode", string(g.Config.Mode), "client_ip", client, "path", r.URL.Path)
				if g.Config.Mode == Enforce {
					http.Error(w, "forbidden", http.StatusForbidden)
					return
				}
			}
		}
		select {
		case slots <- struct{}{}:
			defer func() { <-slots }()
		default:
			g.Overload.Inc()
			w.Header().Set("Retry-After", "1")
			http.Error(w, "temporarily unavailable", http.StatusServiceUnavailable)
			return
		}
		next.ServeHTTP(w, r)
	})
}

// check returns the resolved client address and a reason when the request fails.
func (g *Guard) check(r *http.Request) (string, string) {
	client, ok := g.clientAddr(r)
	if !ok {
		return "", "bad_client_address"
	}
	if len(g.Config.Allowed) > 0 && !contains(g.Config.Allowed, client) {
		return client.String(), "source_not_allowed"
	}
	if g.Config.tokenDigest != nil {
		presented := r.Header.Get(TokenHeader)
		if presented == "" {
			if bearer, found := strings.CutPrefix(r.Header.Get("Authorization"), "Bearer "); found {
				presented = bearer
			}
		}
		digest := sha256.Sum256([]byte(presented))
		if presented == "" || subtle.ConstantTimeCompare(digest[:], g.Config.tokenDigest[:]) != 1 {
			return client.String(), "bad_token"
		}
	}
	return client.String(), ""
}

// clientAddr honours X-Forwarded-For only from trusted proxies, reading it from
// the right so a client cannot prepend a forged address.
func (g *Guard) clientAddr(r *http.Request) (netip.Addr, bool) {
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		host = r.RemoteAddr
	}
	addr, err := netip.ParseAddr(host)
	if err != nil {
		return netip.Addr{}, false
	}
	addr = addr.Unmap()
	if !contains(g.Config.TrustedProxies, addr) {
		return addr, true
	}
	hops := strings.Split(strings.Join(r.Header.Values("X-Forwarded-For"), ","), ",")
	for i := len(hops) - 1; i >= 0; i-- {
		hop := strings.TrimSpace(hops[i])
		if hop == "" {
			continue
		}
		parsed, err := netip.ParseAddr(hop)
		if err != nil {
			return netip.Addr{}, false
		}
		addr = parsed.Unmap()
		if !contains(g.Config.TrustedProxies, addr) {
			return addr, true
		}
	}
	return addr, true
}

func contains(prefixes []netip.Prefix, addr netip.Addr) bool {
	for _, p := range prefixes {
		if p.Contains(addr) {
			return true
		}
	}
	return false
}
