package smsdispatch

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
	"strings"
	"time"
)

type Config struct {
	Endpoint, CPID, Password, Username, BearerToken, SourceAddress string
	Client                                                         *http.Client
}

type Dispatcher struct {
	DB     *sql.DB
	Config Config
}

type message struct {
	id                      int64
	productID, subscriberID int64
	attempts                int
	createdAt               time.Time
	msisdn, body, requestID string
}

type outcome int

const (
	accepted outcome = iota
	// rejected means the provider answered 2xx with an explicit non-zero
	// status, so the SMS was not taken.
	rejected
	// uncertain covers timeouts, transport errors, non-2xx answers, and
	// unparsed bodies: the SMS may have been sent.
	uncertain
)

// resendDelay follows sms_reprocess RETRY_TIME (+5 seconds).
const resendDelay = 5 * time.Second

func (c Config) validate() error {
	u, err := url.Parse(c.Endpoint)
	if err != nil || u.Host == "" || (u.Scheme != "https" && !(u.Scheme == "http" && loopback(u.Hostname()))) {
		return errors.New("SMS endpoint must use HTTPS or loopback HTTP for tests")
	}
	if c.CPID == "" || c.Password == "" || c.Username == "" || c.BearerToken == "" || c.SourceAddress == "" {
		return errors.New("SMS provider configuration is incomplete")
	}
	return nil
}

func loopback(host string) bool {
	ip := net.ParseIP(host)
	return host == "localhost" || (ip != nil && ip.IsLoopback())
}

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
	status, result, sendErr := d.send(ctx, *item)
	state, eventKind := "NEEDS_REVIEW", "NEEDS_REVIEW"
	var next any
	switch {
	case sendErr != nil || result == uncertain:
	case result == accepted:
		state, eventKind = "ACCEPTED", "ACCEPTED"
	case item.attempts == 1 && sameDarDay(item.createdAt, time.Now()):
		// PHP resends a refused SMS once, a few seconds later, the same day.
		state, eventKind, next = "PENDING", "REJECTED_RETRY", time.Now().UTC().Add(resendDelay)
	default:
		state, eventKind = "REJECTED", "REJECTED"
	}
	updateCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	tx, err := d.DB.BeginTx(updateCtx, nil)
	if err != nil {
		return true, err
	}
	defer tx.Rollback()
	res, err := tx.ExecContext(updateCtx, `UPDATE outbound_messages SET delivery_state=?, next_attempt_at=?,
		sent_at=CASE WHEN ?='ACCEPTED' THEN UTC_TIMESTAMP(6) ELSE NULL END
		WHERE id=? AND delivery_state='SENDING'`, state, next, state, item.id)
	if err != nil {
		return true, err
	}
	rows, err := res.RowsAffected()
	if err != nil || rows != 1 {
		return true, errors.New("SMS state changed during dispatch")
	}
	if _, err := tx.ExecContext(updateCtx, `INSERT INTO outbound_message_events
		(outbound_message_id,event_kind,provider_status,source_event_key,occurred_at)
		VALUES (?,?,?,?,UTC_TIMESTAMP(6))`, item.id, eventKind, status, item.requestID); err != nil {
		return true, err
	}
	if err := tx.Commit(); err != nil {
		return true, err
	}
	return true, sendErr
}

func (d Dispatcher) claim(ctx context.Context) (*message, error) {
	tx, err := d.DB.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	var item message
	err = tx.QueryRowContext(ctx, `SELECT m.id,m.product_id,m.subscriber_id,s.msisdn,m.body_text,m.send_attempts,m.created_at
		FROM outbound_messages m JOIN products p ON p.id=m.product_id JOIN subscribers s ON s.id=m.subscriber_id
		WHERE p.code='soka' AND (m.message_kind IN ('POINT_PURCHASE_RESULT','POINT_PURCHASE_MENU','QUESTION','REWARD_MSG','POINT_MSG','HELP_MESSAGE','ONDOA_UNSUB_SMS','LOW_BALANCE','ERROR','BOOM_CREDIT_NOTIFY','NON_SUBSCRIBER','INACTIVE_SUB','OPEN_QUESTION') OR m.message_kind LIKE 'PREDICT\\_%')
		AND m.delivery_state='PENDING' AND (m.next_attempt_at IS NULL OR m.next_attempt_at <= UTC_TIMESTAMP(6))
		ORDER BY m.id LIMIT 1 FOR UPDATE SKIP LOCKED`).
		Scan(&item.id, &item.productID, &item.subscriberID, &item.msisdn, &item.body, &item.attempts, &item.createdAt)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	item.attempts++
	// A resend gets its own request ID, as PHP's new InteractiveRequest does.
	prefix := "I"
	if item.attempts > 1 {
		prefix = "IR"
	}
	item.requestID = prefix + strconv.FormatInt(item.id, 10) + item.msisdn
	if _, err := tx.ExecContext(ctx, `UPDATE outbound_messages SET delivery_state='SENDING', provider_reference=?, send_attempts=? WHERE id=?`,
		item.requestID, item.attempts, item.id); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return &item, nil
}

func sameDarDay(a, b time.Time) bool {
	loc, err := time.LoadLocation("Africa/Dar_es_Salaam")
	if err != nil {
		loc = time.FixedZone("EAT", 3*60*60)
	}
	return a.In(loc).Format("2006-01-02") == b.In(loc).Format("2006-01-02")
}

func (d Dispatcher) send(ctx context.Context, item message) (string, outcome, error) {
	location, err := time.LoadLocation("Africa/Dar_es_Salaam")
	if err != nil {
		return "", uncertain, err
	}
	stamp := time.Now().In(location).Format("20060102150405")
	hash := md5.Sum([]byte(d.Config.CPID + d.Config.Password + stamp))
	fields := []map[string]string{
		{"name": "Msisdn", "value": item.msisdn},
		{"name": "Content", "value": item.body},
		{"name": "OfferCode", "value": "017008600010"},
		{"name": "CpId", "value": d.Config.CPID},
	}
	data, err := json.Marshal(map[string]any{
		"requestId": item.requestID, "requestTimeStamp": stamp, "channel": "10",
		"sourceAddress": d.Config.SourceAddress, "operation": "InteractiveRequest",
		"username": d.Config.Username, "password": hex.EncodeToString(hash[:]),
		"requestParam": map[string]any{"data": fields},
	})
	if err != nil {
		return "", uncertain, err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, d.Config.Endpoint, bytes.NewReader(data))
	if err != nil {
		return "", uncertain, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+d.Config.BearerToken)
	client := d.Config.Client
	if client == nil {
		client = &http.Client{Timeout: 10 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return errors.New("SMS redirect refused") }}
	}
	resp, err := client.Do(req)
	if err != nil {
		return "", uncertain, fmt.Errorf("SMS provider request uncertain: %w", err)
	}
	defer resp.Body.Close()
	body, err := io.ReadAll(io.LimitReader(resp.Body, 4096))
	if err != nil {
		return "", uncertain, err
	}
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return strconv.Itoa(resp.StatusCode), uncertain, nil
	}
	var envelope struct {
		ResponseParam struct {
			Status any `json:"status"`
		} `json:"responseParam"`
	}
	if strings.Contains(string(body), "0: Accepted for delivery") {
		return "accepted", accepted, nil
	}
	if json.Unmarshal(body, &envelope) != nil || envelope.ResponseParam.Status == nil {
		return "unconfirmed", uncertain, nil
	}
	status := fmt.Sprint(envelope.ResponseParam.Status)
	if status == "0" {
		return "accepted", accepted, nil
	}
	if len(status) > 70 {
		status = status[:70]
	}
	return "rejected:" + status, rejected, nil
}
