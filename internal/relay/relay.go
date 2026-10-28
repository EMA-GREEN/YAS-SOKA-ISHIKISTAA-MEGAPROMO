package relay

import (
	"context"
	"crypto/rand"
	"database/sql"
	"errors"
	"log/slog"
	"strconv"
	"time"

	amqp "github.com/rabbitmq/amqp091-go"
	"yas-modernization/internal/messaging"
	"yas-modernization/internal/obs"
)

type Relay struct {
	DB      *sql.DB
	AMQPURL string
	Log     *slog.Logger
	Metrics *Metrics
	// BatchSize bounds the events claimed and published together; 0 means 100.
	BatchSize int
}

// Metrics counts relay publishes. A nil value records nothing.
type Metrics struct {
	Published *obs.CounterVec // labels: product, kind
	Failed    *obs.CounterVec // labels: product, kind
	Connected *obs.GaugeVec
}

func NewMetrics(reg *obs.Registry) *Metrics {
	return &Metrics{
		Published: reg.Counter("yas_relay_published_total", "Outbox events confirmed by the broker and marked published.", "product", "kind"),
		Failed:    reg.Counter("yas_relay_publish_failures_total", "Outbox publish attempts that failed and were rescheduled.", "product", "kind"),
		Connected: reg.Gauge("yas_relay_connected", "1 while the relay holds a confirmed broker channel."),
	}
}

// Event kinds seen in the PHP callback controllers, plus the M-Push and
// TigoPesa push kinds the Go intake assigns. The kind comes from the
// provider payload, so anything else is counted as "other" to bound labels.
var knownKinds = map[string]bool{"Payment": true, "InteractiveResponse": true, "Activation": true,
	"DeActivation": true, "RequestSendSms": true, "INTERACTION": true, "MpushSubsInit": true, "TigoPesaPushResult": true}

func kindLabel(kind string) string {
	if knownKinds[kind] {
		return kind
	}
	return "other"
}

func (m *Metrics) published(product, kind string) {
	if m != nil {
		m.Published.Inc(product, kindLabel(kind))
	}
}

func (m *Metrics) failed(product, kind string) {
	if m != nil {
		m.Failed.Inc(product, kindLabel(kind))
	}
}

func (m *Metrics) connected(up bool) {
	if m == nil {
		return
	}
	v := 0.0
	if up {
		v = 1
	}
	m.Connected.Set(v)
}

type pending struct {
	id, eventID   int64
	product, kind string
	payload       []byte
	attempts      int
	leaseToken    [16]byte
}

func (r Relay) Run(ctx context.Context) error {
	if r.AMQPURL == "" {
		return errors.New("AMQP_URL is required")
	}
	for ctx.Err() == nil {
		if err := r.runConnection(ctx); err != nil && ctx.Err() == nil {
			r.Log.Error("relay connection interrupted", "error", err)
		}
		select {
		case <-ctx.Done():
			return nil
		case <-time.After(2 * time.Second):
		}
	}
	return nil
}

func (r Relay) runConnection(ctx context.Context) error {
	conn, err := amqp.Dial(r.AMQPURL)
	if err != nil {
		return errors.New("AMQP connection failed")
	}
	defer conn.Close()
	ch, err := conn.Channel()
	if err != nil {
		return err
	}
	defer ch.Close()
	if err := ch.ExchangeDeclare("yas.callbacks", "topic", true, false, false, false, nil); err != nil {
		return err
	}
	if err := messaging.DeclareParked(ch); err != nil {
		return err
	}
	if err := messaging.DeclareSokaPointPurchases(ch); err != nil {
		return err
	}
	if err := messaging.DeclareSokaMO(ch); err != nil {
		return err
	}
	if err := messaging.DeclareIshikistaaMO(ch); err != nil {
		return err
	}
	if err := messaging.DeclareIshikistaaPayments(ch); err != nil {
		return err
	}
	if err := ch.Confirm(false); err != nil {
		return err
	}
	r.Metrics.connected(true)
	defer r.Metrics.connected(false)
	batchSize := r.BatchSize
	if batchSize <= 0 {
		batchSize = 100
	}
	// Buffers hold a whole batch so the AMQP reader never blocks on them.
	confirms := ch.NotifyPublish(make(chan amqp.Confirmation, batchSize))
	returns := ch.NotifyReturn(make(chan amqp.Return, batchSize))
	for ctx.Err() == nil {
		batch, err := r.claim(ctx, batchSize)
		if err != nil {
			return err
		}
		if len(batch) == 0 {
			select {
			case <-ctx.Done():
				return nil
			case <-time.After(time.Second):
				continue
			}
		}
		if err := r.publishBatch(ctx, ch, confirms, returns, batch); err != nil {
			return err
		}
	}
	return nil
}

// publishBatch publishes the claimed events with pipelined publisher
// confirms, then marks the confirmed, routed ones published in one update.
// Events that were refused, returned, or unconfirmed are rescheduled, and the
// connection is reopened, as for a single failed publish.
func (r Relay) publishBatch(ctx context.Context, ch *amqp.Channel, confirms <-chan amqp.Confirmation, returns <-chan amqp.Return, batch []pending) error {
	publishCtx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()
	sent := 0
	var publishErr error
	for _, event := range batch {
		if publishErr = ch.PublishWithContext(publishCtx, "yas.callbacks", event.product+"."+event.kind, true, false, amqp.Publishing{
			ContentType: "application/json", DeliveryMode: amqp.Persistent, MessageId: strconv.FormatInt(event.eventID, 10), Body: event.payload,
		}); publishErr != nil {
			break
		}
		sent++
	}
	// Confirmations arrive in publish order. A mandatory return reaches its
	// channel before the matching confirmation, so once all confirmations are
	// in, every return is already queued.
	acked := make([]bool, len(batch))
confirming:
	for n := 0; n < sent; n++ {
		select {
		case confirm, ok := <-confirms:
			if !ok {
				publishErr = errors.New("broker channel closed before confirming")
				break confirming
			}
			acked[n] = confirm.Ack
		case <-publishCtx.Done():
			publishErr = publishCtx.Err()
			break confirming
		}
	}
	returned := map[string]bool{}
	for drained := false; !drained; {
		select {
		case ret := <-returns:
			returned[ret.MessageId] = true
		default:
			drained = true
		}
	}
	var published []pending
	failed := 0
	for i, event := range batch {
		if acked[i] && !returned[strconv.FormatInt(event.eventID, 10)] {
			published = append(published, event)
			continue
		}
		failed++
		r.Log.Warn("outbox publish failed", "event_id", event.eventID, "returned", returned[strconv.FormatInt(event.eventID, 10)])
		r.Metrics.failed(event.product, event.kind)
		if err := r.fail(ctx, event); err != nil {
			return err
		}
	}
	if len(published) > 0 {
		ids := make([]any, 0, len(published)+1)
		placeholders := ""
		for i, event := range published {
			if i > 0 {
				placeholders += ","
			}
			placeholders += "?"
			ids = append(ids, event.id)
		}
		ids = append(ids, published[0].leaseToken[:])
		result, err := r.DB.ExecContext(ctx, `UPDATE callback_outbox SET published_at = UTC_TIMESTAMP(6), locked_until = NULL, lease_token = NULL
            WHERE id IN (`+placeholders+`) AND lease_token = ? AND published_at IS NULL`, ids...)
		if err != nil {
			return err
		}
		rows, err := result.RowsAffected()
		if err != nil || rows != int64(len(published)) {
			return errors.New("outbox lease lost after publish")
		}
		for _, event := range published {
			r.Metrics.published(event.product, event.kind)
		}
	}
	if failed > 0 {
		if publishErr == nil {
			publishErr = errors.New("broker refused or returned some messages")
		}
		return publishErr
	}
	return nil
}

// claim leases up to n ready events under one lease token.
func (r Relay) claim(ctx context.Context, n int) ([]pending, error) {
	tx, err := r.DB.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	rows, err := tx.QueryContext(ctx, `SELECT id, event_id, product, event_kind, payload_json, attempts
        FROM callback_outbox WHERE published_at IS NULL AND dead_lettered_at IS NULL
        AND next_attempt_at <= UTC_TIMESTAMP(6) AND (locked_until IS NULL OR locked_until < UTC_TIMESTAMP(6))
        ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED`, n)
	if err != nil {
		return nil, err
	}
	var batch []pending
	for rows.Next() {
		var p pending
		if err := rows.Scan(&p.id, &p.eventID, &p.product, &p.kind, &p.payload, &p.attempts); err != nil {
			rows.Close()
			return nil, err
		}
		batch = append(batch, p)
	}
	rows.Close()
	if err := rows.Err(); err != nil || len(batch) == 0 {
		return nil, err
	}
	var token [16]byte
	if _, err := rand.Read(token[:]); err != nil {
		return nil, err
	}
	ids := make([]any, 0, len(batch)+1)
	ids = append(ids, token[:])
	placeholders := ""
	for i := range batch {
		batch[i].leaseToken = token
		if i > 0 {
			placeholders += ","
		}
		placeholders += "?"
		ids = append(ids, batch[i].id)
	}
	if _, err := tx.ExecContext(ctx, `UPDATE callback_outbox SET locked_until = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 30 SECOND), lease_token = ?
		WHERE id IN (`+placeholders+`)`, ids...); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return batch, nil
}

func (r Relay) fail(ctx context.Context, p pending) error {
	tries := p.attempts + 1
	if tries >= 20 {
		_, err := r.DB.ExecContext(ctx, `UPDATE callback_outbox SET attempts = ?, dead_lettered_at = UTC_TIMESTAMP(6), locked_until = NULL, lease_token = NULL,
            last_error = 'broker publish failed' WHERE id = ? AND lease_token = ?`, tries, p.id, p.leaseToken[:])
		return err
	}
	delay := 1 << min(tries, 8)
	_, err := r.DB.ExecContext(ctx, `UPDATE callback_outbox SET attempts = ?, next_attempt_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND),
        locked_until = NULL, lease_token = NULL, last_error = 'broker publish failed' WHERE id = ? AND lease_token = ?`, tries, delay, p.id, p.leaseToken[:])
	return err
}

func (r Relay) Backlog(ctx context.Context) (int64, error) {
	var count int64
	err := r.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM callback_outbox WHERE published_at IS NULL AND dead_lettered_at IS NULL`).Scan(&count)
	return count, err
}

// OldestPendingAge is the age in seconds of the oldest unpublished event, or 0.
func (r Relay) OldestPendingAge(ctx context.Context) (float64, error) {
	var age sql.NullFloat64
	err := r.DB.QueryRowContext(ctx, `SELECT TIMESTAMPDIFF(MICROSECOND, MIN(created_at), UTC_TIMESTAMP(6)) / 1e6
		FROM callback_outbox WHERE published_at IS NULL AND dead_lettered_at IS NULL`).Scan(&age)
	return age.Float64, err
}

func (r Relay) DeadLetters(ctx context.Context) (int64, error) {
	var count int64
	err := r.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM callback_outbox WHERE dead_lettered_at IS NOT NULL`).Scan(&count)
	return count, err
}
