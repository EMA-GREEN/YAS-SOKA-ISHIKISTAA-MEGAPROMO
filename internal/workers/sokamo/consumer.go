package sokamo

import (
	"context"
	"errors"
	"log/slog"
	"strconv"
	"time"

	amqp "github.com/rabbitmq/amqp091-go"
	"yas-modernization/internal/messaging"
)

type Consumer struct {
	Processor Processor
	AMQPURL   string
	Log       *slog.Logger
}

func (c Consumer) Run(ctx context.Context) error {
	if c.AMQPURL == "" {
		return errors.New("AMQP_URL is required")
	}
	for ctx.Err() == nil {
		if err := c.consume(ctx); err != nil && ctx.Err() == nil {
			c.Log.Error("Soka point MO consumer interrupted", "error", err)
		}
		select {
		case <-ctx.Done():
			return nil
		case <-time.After(2 * time.Second):
		}
	}
	return nil
}

func (c Consumer) consume(ctx context.Context) error {
	conn, err := amqp.Dial(c.AMQPURL)
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
	if err := messaging.DeclareSokaMO(ch); err != nil {
		return err
	}
	if err := ch.Qos(1, 0, false); err != nil {
		return err
	}
	deliveries, err := ch.Consume("yas.soka.point-mo", "", false, false, false, false, nil)
	if err != nil {
		return err
	}
	for {
		select {
		case <-ctx.Done():
			return nil
		case delivery, ok := <-deliveries:
			if !ok {
				return errors.New("Soka point MO queue closed")
			}
			callbackID, err := strconv.ParseInt(delivery.MessageId, 10, 64)
			if err != nil || callbackID <= 0 {
				c.Log.Warn("point MO message has invalid callback ID")
				if err := delivery.Nack(false, false); err != nil {
					return err
				}
				continue
			}
			processCtx, cancel := context.WithTimeout(ctx, 10*time.Second)
			err = c.Processor.Process(processCtx, callbackID)
			cancel()
			switch {
			case err == nil, errors.Is(err, ErrNotApplicable):
				if err := delivery.Ack(false); err != nil {
					return err
				}
			case errors.Is(err, ErrNeedsReview):
				c.Log.Error("point MO callback needs review", "callback_id", callbackID, "error", err)
				if err := delivery.Nack(false, false); err != nil {
					return err
				}
			default:
				// Closing the connection requeues the unacknowledged delivery.
				return err
			}
		}
	}
}
