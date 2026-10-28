// Package tigoauth supplies the bearer token for Tigo API calls. PHP's
// UserAccessToken.php logs in to /api/auth/login, assumes the token lasts ten
// days, and copies it into every product database for the workers to read.
// Here each process logs in itself and keeps the token in memory.
package tigoauth

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"strconv"
	"sync"
	"time"
)

// Source returns a token for the next request.
type Source interface {
	Token(ctx context.Context) (string, error)
}

// Static is a fixed token, for tests or an operator-managed secret.
type Static string

func (s Static) Token(context.Context) (string, error) {
	if s == "" {
		return "", errors.New("no API token configured")
	}
	return string(s), nil
}

// Client logs in and reuses the token until TTL has passed.
type Client struct {
	URL, Username, Password string
	TTL                     time.Duration
	HTTP                    *http.Client
	Now                     func() time.Time

	mu      sync.Mutex
	token   string
	fetched time.Time
}

// MaxTTL is the lifetime PHP assumes for a token.
const MaxTTL = 10 * 24 * time.Hour

func (c *Client) Token(ctx context.Context) (string, error) {
	c.mu.Lock()
	defer c.mu.Unlock()
	now := time.Now()
	if c.Now != nil {
		now = c.Now()
	}
	if c.token != "" && now.Sub(c.fetched) < c.TTL {
		return c.token, nil
	}
	token, err := c.login(ctx)
	if err != nil {
		return "", err
	}
	c.token, c.fetched = token, now
	return token, nil
}

func (c *Client) login(ctx context.Context) (string, error) {
	body, err := json.Marshal(map[string]string{"username": c.Username, "password": c.Password})
	if err != nil {
		return "", err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.URL, bytes.NewReader(body))
	if err != nil {
		return "", err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Requested-With", "XMLHttpRequest")
	client := c.HTTP
	if client == nil {
		client = &http.Client{Timeout: 10 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return errors.New("login redirect refused") }}
	}
	resp, err := client.Do(req)
	if err != nil {
		return "", fmt.Errorf("Tigo login failed: %w", err)
	}
	defer resp.Body.Close()
	raw, err := io.ReadAll(io.LimitReader(resp.Body, 16<<10))
	if err != nil {
		return "", err
	}
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return "", fmt.Errorf("Tigo login returned HTTP %d", resp.StatusCode)
	}
	var reply struct {
		Token string `json:"token"`
	}
	if json.Unmarshal(raw, &reply) != nil || reply.Token == "" {
		return "", errors.New("Tigo login reply has no token")
	}
	return reply.Token, nil
}

// FromEnv returns a login Client when TIGO_LOGIN_URL is set, with
// TIGO_LOGIN_USERNAME, TIGO_LOGIN_PASSWORD, and TIGO_TOKEN_TTL (a Go duration,
// default 24h, at most 240h). Otherwise it returns the fixed token in the
// named variable. Errors never include secrets.
func FromEnv(getenv func(string) string, staticVar string) (Source, error) {
	loginURL := getenv("TIGO_LOGIN_URL")
	if loginURL == "" {
		if getenv(staticVar) == "" {
			return nil, fmt.Errorf("set TIGO_LOGIN_URL or %s", staticVar)
		}
		return Static(getenv(staticVar)), nil
	}
	u, err := url.Parse(loginURL)
	if err != nil || u.Host == "" || (u.Scheme != "https" && !(u.Scheme == "http" && loopback(u.Hostname()))) {
		return nil, errors.New("TIGO_LOGIN_URL must use HTTPS or loopback HTTP for tests")
	}
	c := &Client{URL: loginURL, Username: getenv("TIGO_LOGIN_USERNAME"), Password: getenv("TIGO_LOGIN_PASSWORD"), TTL: 24 * time.Hour}
	if c.Username == "" || c.Password == "" {
		return nil, errors.New("TIGO_LOGIN_USERNAME and TIGO_LOGIN_PASSWORD are required with TIGO_LOGIN_URL")
	}
	if raw := getenv("TIGO_TOKEN_TTL"); raw != "" {
		ttl, err := time.ParseDuration(raw)
		if err != nil || ttl <= 0 || ttl > MaxTTL {
			return nil, errors.New("TIGO_TOKEN_TTL must be a positive duration of at most " + strconv.Itoa(int(MaxTTL.Hours())) + "h")
		}
		c.TTL = ttl
	}
	return c, nil
}

func loopback(host string) bool {
	ip := net.ParseIP(host)
	return host == "localhost" || (ip != nil && ip.IsLoopback())
}
