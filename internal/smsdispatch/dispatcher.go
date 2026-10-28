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
	msisdn, body, requestID string
}

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
	status, accepted, sendErr := d.send(ctx, *item)
	state, eventKind := "NEEDS_REVIEW", "NEEDS_REVIEW"
	if accepted {
		state, eventKind = "ACCEPTED", "ACCEPTED"
	}
	updateCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	tx, err := d.DB.BeginTx(updateCtx, nil)
	if err != nil {
		return true, err
	}
	defer tx.Rollback()
	result, err := tx.ExecContext(updateCtx, `UPDATE outbound_messages SET delivery_state=?, sent_at=CASE WHEN ?='ACCEPTED' THEN UTC_TIMESTAMP(6) ELSE NULL END
		WHERE id=? AND delivery_state='SENDING'`, state, state, item.id)
	if err != nil {
		return true, err
	}
	rows, err := result.RowsAffected()
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
	err = tx.QueryRowContext(ctx, `SELECT m.id,m.product_id,m.subscriber_id,s.msisdn,m.body_text
		FROM outbound_messages m JOIN products p ON p.id=m.product_id JOIN subscribers s ON s.id=m.subscriber_id
		WHERE p.code='soka' AND m.message_kind IN ('POINT_PURCHASE_RESULT','POINT_PURCHASE_MENU','QUESTION','REWARD_MSG','POINT_MSG','HELP_MESSAGE','ONDOA_UNSUB_SMS','LOW_BALANCE','ERROR','BOOM_CREDIT_NOTIFY','NON_SUBSCRIBER','INACTIVE_SUB','OPEN_QUESTION')
		AND m.delivery_state='PENDING' ORDER BY m.id LIMIT 1 FOR UPDATE SKIP LOCKED`).
		Scan(&item.id, &item.productID, &item.subscriberID, &item.msisdn, &item.body)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	item.requestID = "I" + strconv.FormatInt(item.id, 10) + item.msisdn
	if _, err := tx.ExecContext(ctx, `UPDATE outbound_messages SET delivery_state='SENDING',provider_reference=? WHERE id=?`, item.requestID, item.id); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return &item, nil
}

func (d Dispatcher) send(ctx context.Context, item message) (string, bool, error) {
	location, err := time.LoadLocation("Africa/Dar_es_Salaam")
	if err != nil {
		return "", false, err
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
		return "", false, err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, d.Config.Endpoint, bytes.NewReader(data))
	if err != nil {
		return "", false, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+d.Config.BearerToken)
	client := d.Config.Client
	if client == nil {
		client = &http.Client{Timeout: 10 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return errors.New("SMS redirect refused") }}
	}
	resp, err := client.Do(req)
	if err != nil {
		return "", false, fmt.Errorf("SMS provider request uncertain: %w", err)
	}
	defer resp.Body.Close()
	body, err := io.ReadAll(io.LimitReader(resp.Body, 4096))
	if err != nil {
		return "", false, err
	}
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return strconv.Itoa(resp.StatusCode), false, nil
	}
	var envelope struct {
		ResponseParam struct {
			Status any `json:"status"`
		} `json:"responseParam"`
	}
	_ = json.Unmarshal(body, &envelope)
	status := fmt.Sprint(envelope.ResponseParam.Status)
	accepted := status == "0" || strings.Contains(string(body), "0: Accepted for delivery")
	if !accepted {
		return "unconfirmed", false, nil
	}
	return "accepted", true, nil
}
