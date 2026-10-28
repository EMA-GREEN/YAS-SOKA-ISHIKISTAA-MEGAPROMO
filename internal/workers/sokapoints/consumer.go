package sokapoints

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
	Metrics   *messaging.ConsumerMetrics
}

const consumerName = "soka-point-purchase"

func (c Consumer) Run(ctx context.Context) error {
	if c.AMQPURL == "" {
		return errors.New("AMQP_URL is required")
	}
	for ctx.Err() == nil {
		if err := c.consume(ctx); err != nil && ctx.Err() == nil {
			c.Log.Error("Soka point purchase consumer interrupted", "error", err)
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
	if err := messaging.DeclareSokaPointPurchases(ch); err != nil {
		return err
	}
	if err := ch.Qos(1, 0, false); err != nil {
		return err
	}
	deliveries, err := ch.Consume("yas.soka.point-purchases", "", false, false, false, false, nil)
	if err != nil {
		return err
	}
	c.Metrics.SetConnected(consumerName, true)
	defer c.Metrics.SetConnected(consumerName, false)
	for {
		select {
		case <-ctx.Done():
			return nil
		case delivery, ok := <-deliveries:
			if !ok {
				return errors.New("Soka point purchase queue closed")
			}
			callbackID, err := strconv.ParseInt(delivery.MessageId, 10, 64)
			if err != nil || callbackID <= 0 {
				c.Log.Warn("point purchase message has invalid callback ID")
				c.Metrics.Observe(consumerName, messaging.OutcomeInvalidID, time.Time{})
				if err := delivery.Nack(false, false); err != nil {
					return err
				}
				continue
			}
			started := time.Now()
			processCtx, cancel := context.WithTimeout(ctx, 10*time.Second)
			err = c.Processor.Process(processCtx, callbackID)
			cancel()
			switch {
			case err == nil, errors.Is(err, ErrNotApplicable):
				outcome := messaging.OutcomeProcessed
				if err != nil {
					outcome = messaging.OutcomeNotApplicable
				}
				c.Metrics.Observe(consumerName, outcome, started)
				if err := delivery.Ack(false); err != nil {
					return err
				}
			case errors.Is(err, ErrInvalid):
				c.Metrics.Observe(consumerName, messaging.OutcomeReview, started)
				c.Log.Error("point purchase callback needs review", "callback_id", callbackID, "error", err)
				if err := delivery.Nack(false, false); err != nil {
					return err
				}
			default:
				c.Metrics.Observe(consumerName, messaging.OutcomeRetry, started)
				// Closing the connection requeues the unacknowledged delivery.
				return err
			}
		}
	}
}
