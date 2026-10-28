package chargedispatch

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
	"strconv"
	"time"
)

type Config struct {
	Endpoint      string
	CPID          string
	Password      string
	Username      string
	BearerToken   string
	SourceAddress string
	Client        *http.Client
}

type Dispatcher struct {
	DB     *sql.DB
	Config Config
}

type intent struct {
	id, paymentID, amount                                       int64
	offer, requestID, msisdn, reference, currency, paymentState string
}

func (c Config) validate() error {
	u, err := url.Parse(c.Endpoint)
	if err != nil || u.Host == "" || (u.Scheme != "https" && !(u.Scheme == "http" && isLoopback(u.Hostname()))) {
		return errors.New("charge endpoint must use HTTPS or loopback HTTP for tests")
	}
	if c.CPID == "" || c.Password == "" || c.Username == "" || c.BearerToken == "" || c.SourceAddress == "" {
		return errors.New("charge provider configuration is incomplete")
	}
	return nil
}

func isLoopback(host string) bool {
	ip := net.ParseIP(host)
	return host == "localhost" || (ip != nil && ip.IsLoopback())
}

// DispatchOne claims at most one charge. Once claimed, a timeout or crash is
// uncertain and must be reviewed; it is never silently retried.
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
	state, reason := "SUBMITTED", ""
	if sendErr != nil || status < 200 || status >= 300 {
		state, reason = "NEEDS_REVIEW", "provider result uncertain"
	}
	updateCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	result, updateErr := d.DB.ExecContext(updateCtx, `UPDATE charge_intents SET state=?, response_status=?, submitted_at=CASE WHEN ?='SUBMITTED' THEN UTC_TIMESTAMP(6) ELSE NULL END, last_error=?
		WHERE id=? AND state='SUBMITTING'`, state, nullableStatus(status), state, nullableText(reason), item.id)
	if updateErr != nil {
		return true, updateErr
	}
	rows, err := result.RowsAffected()
	if err != nil || rows != 1 {
		return true, errors.New("charge intent state changed during dispatch")
	}
	return true, sendErr
}

func (d Dispatcher) claim(ctx context.Context) (*intent, error) {
	tx, err := d.DB.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	var item intent
	err = tx.QueryRowContext(ctx, `SELECT ci.id, ci.payment_attempt_id, pa.amount_minor, ci.offer_code, pa.external_request_id,
		s.msisdn, COALESCE(ci.reference_id,''), pa.currency, pa.state
		FROM charge_intents ci JOIN payment_attempts pa ON pa.id=ci.payment_attempt_id
		JOIN products p ON p.id=pa.product_id JOIN subscribers s ON s.id=pa.subscriber_id
		WHERE ci.state='PENDING' AND p.code='soka' AND pa.payment_kind='POINT_PURCHASE'
		ORDER BY ci.id LIMIT 1 FOR UPDATE SKIP LOCKED`).Scan(&item.id, &item.paymentID, &item.amount,
		&item.offer, &item.requestID, &item.msisdn, &item.reference, &item.currency, &item.paymentState)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	if item.paymentState != "PENDING" || item.currency != "TZS" || item.amount <= 0 ||
		(item.offer != "017008600005" && item.offer != "017008600272") {
		if _, err := tx.ExecContext(ctx, `UPDATE charge_intents SET state='NEEDS_REVIEW', last_error='payment state or amount invalid' WHERE id=?`, item.id); err != nil {
			return nil, err
		}
		return nil, tx.Commit()
	}
	if _, err := tx.ExecContext(ctx, `UPDATE charge_intents SET state='SUBMITTING', attempts=attempts+1, request_started_at=UTC_TIMESTAMP(6) WHERE id=?`, item.id); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return &item, nil
}

func (d Dispatcher) send(ctx context.Context, item intent) (int, error) {
	location, err := time.LoadLocation("Africa/Dar_es_Salaam")
	if err != nil {
		return 0, err
	}
	stamp := time.Now().In(location).Format("20060102150405")
	hash := md5.Sum([]byte(d.Config.CPID + d.Config.Password + stamp))
	fields := []map[string]string{
		{"name": "OfferCode", "value": item.offer},
		{"name": "Msisdn", "value": item.msisdn},
		{"name": "ReferenceId", "value": item.reference},
		{"name": "TypeOfService", "value": "InteractiveService"},
		{"name": "Language", "value": "1"},
		{"name": "ChargeAmount", "value": strconv.FormatInt(item.amount, 10)},
		{"name": "CpId", "value": d.Config.CPID},
	}
	body, err := json.Marshal(map[string]any{
		"password":         hex.EncodeToString(hash[:]),
		"sourceAddress":    d.Config.SourceAddress,
		"requestId":        item.requestID,
		"requestTimeStamp": stamp,
		"channel":          "10",
		"requestParam":     map[string]any{"data": fields},
		"operation":        "Payment",
		"username":         d.Config.Username,
	})
	if err != nil {
		return 0, err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, d.Config.Endpoint, bytes.NewReader(body))
	if err != nil {
		return 0, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+d.Config.BearerToken)
	client := d.Config.Client
	if client == nil {
		client = &http.Client{Timeout: 10 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return errors.New("charge redirect refused") }}
	}
	resp, err := client.Do(req)
	if err != nil {
		return 0, fmt.Errorf("charge provider request uncertain: %w", err)
	}
	defer resp.Body.Close()
	// Drain a small bounded response so connections can be reused; never log it.
	_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 4096))
	return resp.StatusCode, nil
}

func nullableStatus(code int) any {
	if code == 0 {
		return nil
	}
	return code
}

func nullableText(value string) any {
	if value == "" {
		return nil
	}
	return value
}
