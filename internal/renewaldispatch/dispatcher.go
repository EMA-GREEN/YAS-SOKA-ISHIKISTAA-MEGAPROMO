// Package renewaldispatch reports Soka subscribe and unsubscribe events to the
// renewal system, as sendRenewal() in YAS-SOKA/SokaMoConsumer.php does. The
// renewal system may start or stop recurring charges, so an uncertain send is
// marked for review and never repeated automatically.
package renewaldispatch

import (
	"bytes"
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"time"
)

// Config maps each renewal_requests target to its endpoint.
type Config struct {
	Endpoints map[string]string
	Client    *http.Client
}

type Dispatcher struct {
	DB     *sql.DB
	Config Config
}

type request struct {
	id                       int64
	msisdn, activity, target string
	product                  string
}

// serviceIDs are the serviceID values each product's sendRenewal sends.
var serviceIDs = map[string]string{"soka": "SOKA", "ishikistaa": "ISHIKISTAA"}

func (c Config) validate() error {
	if len(c.Endpoints) == 0 {
		return errors.New("renewal endpoints are not configured")
	}
	for target, endpoint := range c.Endpoints {
		u, err := url.Parse(endpoint)
		if err != nil || u.Host == "" || (u.Scheme != "https" && !(u.Scheme == "http" && loopback(u.Hostname()))) {
			return fmt.Errorf("renewal endpoint for %s must use HTTPS or loopback HTTP for tests", target)
		}
	}
	return nil
}

func loopback(host string) bool {
	ip := net.ParseIP(host)
	return host == "localhost" || (ip != nil && ip.IsLoopback())
}

// DispatchOne sends at most one pending request whose target is configured.
func (d Dispatcher) DispatchOne(ctx context.Context) (bool, error) {
	if d.DB == nil {
		return false, errors.New("database is required")
	}
	if err := d.Config.validate(); err != nil {
		return false, err
	}
	item, err := d.claim(ctx)
	if err != nil || item == nil {
		return false, err
	}
	status, sendErr := d.send(ctx, *item)
	state, reason := "SENT", ""
	if sendErr != nil || status < 200 || status >= 300 {
		state, reason = "NEEDS_REVIEW", "renewal system result uncertain"
	}
	updateCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	var code any
	if status != 0 {
		code = status
	}
	var lastError any
	if reason != "" {
		lastError = reason
	}
	result, err := d.DB.ExecContext(updateCtx, `UPDATE renewal_requests SET state=?, response_status=?, last_error=?,
		sent_at=CASE WHEN ?='SENT' THEN UTC_TIMESTAMP(6) ELSE NULL END WHERE id=? AND state='SENDING'`,
		state, code, lastError, state, item.id)
	if err != nil {
		return true, err
	}
	if rows, err := result.RowsAffected(); err != nil || rows != 1 {
		return true, errors.New("renewal request state changed during dispatch")
	}
	return true, sendErr
}

func (d Dispatcher) claim(ctx context.Context) (*request, error) {
	targets := make([]any, 0, len(d.Config.Endpoints))
	placeholders := ""
	for target := range d.Config.Endpoints {
		if placeholders != "" {
			placeholders += ","
		}
		placeholders += "?"
		targets = append(targets, target)
	}
	tx, err := d.DB.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	var item request
	err = tx.QueryRowContext(ctx, `SELECT r.id, s.msisdn, r.activity_type, r.target, p.code
		FROM renewal_requests r JOIN subscribers s ON s.id=r.subscriber_id JOIN products p ON p.id=r.product_id
		WHERE r.state='PENDING' AND p.code IN ('soka','ishikistaa') AND r.target IN (`+placeholders+`)
		ORDER BY r.id LIMIT 1 FOR UPDATE SKIP LOCKED`, targets...).Scan(&item.id, &item.msisdn, &item.activity, &item.target, &item.product)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	if _, err := tx.ExecContext(ctx, `UPDATE renewal_requests SET state='SENDING', request_started_at=UTC_TIMESTAMP(6) WHERE id=?`, item.id); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return &item, nil
}

func (d Dispatcher) send(ctx context.Context, item request) (int, error) {
	body, err := json.Marshal(map[string]string{"msisdn": item.msisdn, "serviceID": serviceIDs[item.product], "activityType": item.activity})
	if err != nil {
		return 0, err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, d.Config.Endpoints[item.target], bytes.NewReader(body))
	if err != nil {
		return 0, err
	}
	req.Header.Set("Content-Type", "application/json")
	client := d.Config.Client
	if client == nil {
		client = &http.Client{Timeout: 10 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return errors.New("renewal redirect refused") }}
	}
	resp, err := client.Do(req)
	if err != nil {
		return 0, fmt.Errorf("renewal request uncertain: %w", err)
	}
	defer resp.Body.Close()
	// PHP only logs the response; drain a bounded amount and never log it.
	_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 4096))
	return resp.StatusCode, nil
}
