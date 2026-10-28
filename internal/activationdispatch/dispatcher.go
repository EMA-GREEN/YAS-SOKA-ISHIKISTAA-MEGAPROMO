// Package activationdispatch sends M-Push YAS subscription-init activations,
// as SokaLib::sendMpushYasSubscriptionActivation does. Activation may start
// subscription billing, so an uncertain result is reviewed, never resent.
package activationdispatch

import (
	"bytes"
	"context"
	"crypto/md5"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"time"

	"yas-modernization/internal/tigoauth"
)

// OfferCode is the activation offer in PHP.
const OfferCode = "017008600010"

type Config struct {
	Endpoint, CPID, Password, Username, AccessToken, SourceAddress string
	Client                                                         *http.Client
	// Tokens supplies the bearer token; nil means the fixed AccessToken.
	Tokens tigoauth.Source
}

type Dispatcher struct {
	DB     *sql.DB
	Config Config
}

type attempt struct {
	id                int64
	requestID, msisdn string
}

func (c Config) validate() error {
	u, err := url.Parse(c.Endpoint)
	if err != nil || u.Host == "" || (u.Scheme != "https" && !(u.Scheme == "http" && loopback(u.Hostname()))) {
		return errors.New("activation endpoint must use HTTPS or loopback HTTP for tests")
	}
	if c.CPID == "" || c.Password == "" || c.Username == "" || c.SourceAddress == "" {
		return errors.New("activation provider configuration is incomplete")
	}
	if c.Tokens == nil && c.AccessToken == "" {
		return errors.New("no API token configured")
	}
	return nil
}

func (c Config) tokens() tigoauth.Source {
	if c.Tokens != nil {
		return c.Tokens
	}
	return tigoauth.Static(c.AccessToken)
}

func loopback(host string) bool {
	ip := net.ParseIP(host)
	return host == "localhost" || (ip != nil && ip.IsLoopback())
}

// DispatchOne sends at most one pending Soka activation.
func (d Dispatcher) DispatchOne(ctx context.Context) (bool, error) {
	if d.DB == nil {
		return false, errors.New("database is required")
	}
	if err := d.Config.validate(); err != nil {
		return false, err
	}
	// Get the token first, so a failed login never leaves a claimed item.
	token, err := d.Config.tokens().Token(ctx)
	if err != nil {
		return false, err
	}
	item, err := d.claim(ctx)
	if err != nil || item == nil {
		return false, err
	}
	httpStatus, providerStatus, accepted, sendErr := d.send(ctx, *item, token)
	state, reason := "SENT", ""
	if sendErr != nil || !accepted {
		state, reason = "NEEDS_REVIEW", "activation result uncertain"
	}
	updateCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	var code, lastError, provider any
	if httpStatus != 0 {
		code = httpStatus
	}
	if reason != "" {
		lastError = reason
	}
	if providerStatus != "" {
		provider = providerStatus
	}
	result, err := d.DB.ExecContext(updateCtx, `UPDATE activation_attempts SET state=?, response_status=?, provider_status=?, last_error=?,
		responded_at=UTC_TIMESTAMP(6) WHERE id=? AND state='SENDING'`, state, code, provider, lastError, item.id)
	if err != nil {
		return true, err
	}
	if rows, err := result.RowsAffected(); err != nil || rows != 1 {
		return true, errors.New("activation state changed during dispatch")
	}
	return true, sendErr
}

func (d Dispatcher) claim(ctx context.Context) (*attempt, error) {
	tx, err := d.DB.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	var item attempt
	err = tx.QueryRowContext(ctx, `SELECT a.id, a.external_request_id, s.msisdn FROM activation_attempts a
		JOIN products p ON p.id=a.product_id JOIN subscribers s ON s.id=a.subscriber_id
		WHERE a.state='PENDING' AND p.code='soka' ORDER BY a.id LIMIT 1 FOR UPDATE SKIP LOCKED`).Scan(&item.id, &item.requestID, &item.msisdn)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	if _, err := tx.ExecContext(ctx, `UPDATE activation_attempts SET state='SENDING', request_started_at=UTC_TIMESTAMP(6) WHERE id=?`, item.id); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return &item, nil
}

func (d Dispatcher) send(ctx context.Context, item attempt, token string) (int, string, bool, error) {
	location, err := time.LoadLocation("Africa/Dar_es_Salaam")
	if err != nil {
		return 0, "", false, err
	}
	stamp := time.Now().In(location).Format("20060102150405")
	hash := md5.Sum([]byte(d.Config.CPID + d.Config.Password + stamp))
	body, err := json.Marshal(map[string]any{
		"requestId": item.requestID, "requestTimeStamp": stamp, "channel": "10",
		"sourceAddress": d.Config.SourceAddress, "operation": "ACTIVATE",
		"username": d.Config.Username, "password": hex.EncodeToString(hash[:]),
		"requestParam": map[string]any{"data": []map[string]string{
			{"name": "OfferCode", "value": OfferCode},
			{"name": "Msisdn", "value": item.msisdn},
			{"name": "CpId", "value": d.Config.CPID},
		}},
	})
	if err != nil {
		return 0, "", false, err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, d.Config.Endpoint, bytes.NewReader(body))
	if err != nil {
		return 0, "", false, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Requested-With", "XMLHttpRequest")
	// PHP sends the token in X-Authorization, not Authorization.
	req.Header.Set("X-Authorization", "Bearer "+token)
	client := d.Config.Client
	if client == nil {
		client = &http.Client{Timeout: 10 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return errors.New("activation redirect refused") }}
	}
	resp, err := client.Do(req)
	if err != nil {
		return 0, "", false, fmt.Errorf("activation request uncertain: %w", err)
	}
	defer resp.Body.Close()
	raw, err := io.ReadAll(io.LimitReader(resp.Body, 4096))
	if err != nil {
		return resp.StatusCode, "", false, err
	}
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return resp.StatusCode, "", false, nil
	}
	var envelope struct {
		ResponseParam struct {
			Status     any `json:"status"`
			StatusCode any `json:"statusCode"`
		} `json:"responseParam"`
	}
	if json.Unmarshal(raw, &envelope) != nil || envelope.ResponseParam.Status == nil {
		return resp.StatusCode, "unparsed", false, nil
	}
	status := fmt.Sprint(envelope.ResponseParam.Status)
	provider := status + "/" + fmt.Sprint(envelope.ResponseParam.StatusCode)
	if len(provider) > 80 {
		provider = provider[:80]
	}
	return resp.StatusCode, provider, status == "0", nil
}
