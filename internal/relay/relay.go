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
)

type Relay struct {
	DB      *sql.DB
	AMQPURL string
	Log     *slog.Logger
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
	if _, err := ch.QueueDeclare("yas.callback.events", true, false, false, false, nil); err != nil {
		return err
	}
	if err := ch.QueueBind("yas.callback.events", "#", "yas.callbacks", false, nil); err != nil {
		return err
	}
	if err := messaging.DeclareSokaPointPurchases(ch); err != nil {
		return err
	}
	if err := messaging.DeclareSokaMO(ch); err != nil {
		return err
	}
	if err := ch.Confirm(false); err != nil {
		return err
	}
	confirms := ch.NotifyPublish(make(chan amqp.Confirmation, 1))
	returns := ch.NotifyReturn(make(chan amqp.Return, 1))
	for ctx.Err() == nil {
		event, err := r.claim(ctx)
		if err != nil {
			return err
		}
		if event == nil {
			select {
			case <-ctx.Done():
				return nil
			case <-time.After(time.Second):
				continue
			}
		}
		publishCtx, cancel := context.WithTimeout(ctx, 10*time.Second)
		err = ch.PublishWithContext(publishCtx, "yas.callbacks", event.product+"."+event.kind, true, false, amqp.Publishing{
			ContentType: "application/json", DeliveryMode: amqp.Persistent, MessageId: strconv.FormatInt(event.eventID, 10), Body: event.payload,
		})
		if err == nil {
			select {
			case confirm, ok := <-confirms:
				if !ok || !confirm.Ack {
					err = errors.New("broker did not confirm publish")
				}
			case <-publishCtx.Done():
				err = publishCtx.Err()
			}
			select {
			case <-returns:
				err = errors.New("broker returned unroutable message")
			default:
			}
		}
		cancel()
		if err != nil {
			r.Log.Warn("outbox publish failed", "event_id", event.eventID, "error", err)
			if updateErr := r.fail(ctx, *event); updateErr != nil {
				return updateErr
			}
			return err
		}
		result, err := r.DB.ExecContext(ctx, `UPDATE callback_outbox SET published_at = UTC_TIMESTAMP(6), locked_until = NULL, lease_token = NULL
            WHERE id = ? AND lease_token = ? AND published_at IS NULL`, event.id, event.leaseToken[:])
		if err != nil {
			return err
		}
		rows, err := result.RowsAffected()
		if err != nil || rows != 1 {
			return errors.New("outbox lease lost after publish")
		}
	}
	return nil
}

func (r Relay) claim(ctx context.Context) (*pending, error) {
	tx, err := r.DB.BeginTx(ctx, nil)
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	var p pending
	err = tx.QueryRowContext(ctx, `SELECT id, event_id, product, event_kind, payload_json, attempts
        FROM callback_outbox WHERE published_at IS NULL AND dead_lettered_at IS NULL
        AND next_attempt_at <= UTC_TIMESTAMP(6) AND (locked_until IS NULL OR locked_until < UTC_TIMESTAMP(6))
        ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED`).Scan(&p.id, &p.eventID, &p.product, &p.kind, &p.payload, &p.attempts)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	if _, err := rand.Read(p.leaseToken[:]); err != nil {
		return nil, err
	}
	if _, err := tx.ExecContext(ctx, `UPDATE callback_outbox SET locked_until = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 30 SECOND), lease_token = ? WHERE id = ?`, p.leaseToken[:], p.id); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return &p, nil
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

func (r Relay) DeadLetters(ctx context.Context) (int64, error) {
	var count int64
	err := r.DB.QueryRowContext(ctx, `SELECT COUNT(*) FROM callback_outbox WHERE dead_lettered_at IS NOT NULL`).Scan(&count)
	return count, err
}
