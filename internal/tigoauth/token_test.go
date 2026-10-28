package tigoauth

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

func TestClientCachesAndRefreshes(t *testing.T) {
	var logins atomic.Int64
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var body map[string]string
		_ = json.NewDecoder(r.Body).Decode(&body)
		if body["username"] != "u" || body["password"] != "test-only" || r.Method != http.MethodPost {
			w.WriteHeader(http.StatusUnauthorized)
			return
		}
		n := logins.Add(1)
		_, _ = w.Write([]byte(`{"token":"t` + string(rune('0'+n)) + `","refreshToken":"r"}`))
	}))
	defer server.Close()
	now := time.Date(2026, 10, 28, 0, 0, 0, 0, time.UTC)
	c := &Client{URL: server.URL, Username: "u", Password: "test-only", TTL: time.Hour, Now: func() time.Time { return now }}
	for i := 0; i < 3; i++ {
		if tok, err := c.Token(context.Background()); err != nil || tok != "t1" {
			t.Fatalf("token %q err=%v", tok, err)
		}
	}
	now = now.Add(time.Hour)
	if tok, err := c.Token(context.Background()); err != nil || tok != "t2" || logins.Load() != 2 {
		t.Fatalf("refresh token %q logins=%d err=%v", tok, logins.Load(), err)
	}
	bad := &Client{URL: server.URL, Username: "u", Password: "wrong", TTL: time.Hour}
	if _, err := bad.Token(context.Background()); err == nil || strings.Contains(err.Error(), "wrong") {
		t.Fatalf("failed login err=%v", err)
	}
	empty := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { _, _ = w.Write([]byte(`{}`)) }))
	defer empty.Close()
	if _, err := (&Client{URL: empty.URL, TTL: time.Hour}).Token(context.Background()); err == nil {
		t.Fatal("a reply without a token must fail")
	}
}

func TestFromEnv(t *testing.T) {
	env := func(m map[string]string) func(string) string { return func(k string) string { return m[k] } }
	if _, err := FromEnv(env(nil), "SMS_BEARER_TOKEN"); err == nil {
		t.Fatal("no token source accepted")
	}
	src, err := FromEnv(env(map[string]string{"SMS_BEARER_TOKEN": "fixed"}), "SMS_BEARER_TOKEN")
	if tok, _ := src.Token(context.Background()); err != nil || tok != "fixed" {
		t.Fatal("static token")
	}
	for name, m := range map[string]map[string]string{
		"plain http":   {"TIGO_LOGIN_URL": "http://tigo.example/api/auth/login", "TIGO_LOGIN_USERNAME": "u", "TIGO_LOGIN_PASSWORD": "p"},
		"no password":  {"TIGO_LOGIN_URL": "https://tigo.example/api/auth/login", "TIGO_LOGIN_USERNAME": "u"},
		"ttl too long": {"TIGO_LOGIN_URL": "https://tigo.example/api/auth/login", "TIGO_LOGIN_USERNAME": "u", "TIGO_LOGIN_PASSWORD": "p", "TIGO_TOKEN_TTL": "300h"},
	} {
		if _, err := FromEnv(env(m), "X"); err == nil {
			t.Errorf("%s accepted", name)
		}
	}
	src, err = FromEnv(env(map[string]string{"TIGO_LOGIN_URL": "https://tigo.example/api/auth/login", "TIGO_LOGIN_USERNAME": "u", "TIGO_LOGIN_PASSWORD": "p", "TIGO_TOKEN_TTL": "6h"}), "X")
	if c, ok := src.(*Client); err != nil || !ok || c.TTL != 6*time.Hour {
		t.Fatalf("login client %v %v", src, err)
	}
}
