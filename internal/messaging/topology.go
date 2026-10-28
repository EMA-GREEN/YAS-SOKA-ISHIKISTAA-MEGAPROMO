package messaging

import amqp "github.com/rabbitmq/amqp091-go"

// DeclareSokaPointPurchases is called by the relay before publishing and by the
// consumer before reading, so messages remain queued when the consumer is down.
func DeclareSokaPointPurchases(ch *amqp.Channel) error {
	if err := ch.ExchangeDeclare("yas.soka.point-purchases.dlx", "direct", true, false, false, false, nil); err != nil {
		return err
	}
	if _, err := ch.QueueDeclare("yas.soka.point-purchases.dead", true, false, false, false, nil); err != nil {
		return err
	}
	if err := ch.QueueBind("yas.soka.point-purchases.dead", "dead", "yas.soka.point-purchases.dlx", false, nil); err != nil {
		return err
	}
	arguments := amqp.Table{
		"x-dead-letter-exchange":    "yas.soka.point-purchases.dlx",
		"x-dead-letter-routing-key": "dead",
	}
	if _, err := ch.QueueDeclare("yas.soka.point-purchases", true, false, false, false, arguments); err != nil {
		return err
	}
	return ch.QueueBind("yas.soka.point-purchases", "soka.Payment", "yas.callbacks", false, nil)
}

// DeclareSokaMO preserves purchase MO callbacks until the dedicated worker is ready.
func DeclareSokaMO(ch *amqp.Channel) error {
	if err := ch.ExchangeDeclare("yas.soka.point-mo.dlx", "direct", true, false, false, false, nil); err != nil {
		return err
	}
	if _, err := ch.QueueDeclare("yas.soka.point-mo.dead", true, false, false, false, nil); err != nil {
		return err
	}
	if err := ch.QueueBind("yas.soka.point-mo.dead", "dead", "yas.soka.point-mo.dlx", false, nil); err != nil {
		return err
	}
	arguments := amqp.Table{"x-dead-letter-exchange": "yas.soka.point-mo.dlx", "x-dead-letter-routing-key": "dead"}
	if _, err := ch.QueueDeclare("yas.soka.point-mo", true, false, false, false, arguments); err != nil {
		return err
	}
	// The MO worker owns customer MOs and the provider's subscription callbacks.
	for _, key := range []string{"soka.InteractiveResponse", "soka.Activation", "soka.RequestSendSms", "soka.DeActivation", "soka.MpushSubsInit"} {
		if err := ch.QueueBind("yas.soka.point-mo", key, "yas.callbacks", false, nil); err != nil {
			return err
		}
	}
	return nil
}

// Callback kinds the intake accepts but no Go worker handles yet. Each product
// parks them in one durable queue so nothing is lost before its worker exists.
// Any other routing key is unroutable, so the relay's mandatory publish fails
// and the event becomes a visible outbox dead letter instead of vanishing.
var parked = map[string][]string{
	"yas.soka.pending":       {},
	"yas.ishikistaa.pending": {"ishikistaa.#"},
	"yas.megapromo.pending":  {"megapromo.#"},
}

// Bindings removed from parked when a worker took the kind over. Broker
// bindings outlive code, so they are removed explicitly; RabbitMQ accepts
// unbinding a binding that does not exist.
var retired = map[string][]string{
	"yas.soka.pending": {"soka.Activation", "soka.DeActivation", "soka.RequestSendSms", "soka.MpushSubsInit"},
}

// DeclareParked declares the holding queues. Workers that take over a kind must
// bind their own queue and move the kind from parked to retired in the same change.
func DeclareParked(ch *amqp.Channel) error {
	for queue, keys := range parked {
		if _, err := ch.QueueDeclare(queue, true, false, false, false, nil); err != nil {
			return err
		}
		for _, key := range keys {
			if err := ch.QueueBind(queue, key, "yas.callbacks", false, nil); err != nil {
				return err
			}
		}
	}
	// Retired queues are a subset of parked ones, so they exist by now.
	for queue, keys := range retired {
		for _, key := range keys {
			if err := ch.QueueUnbind(queue, key, "yas.callbacks", nil); err != nil {
				return err
			}
		}
	}
	return nil
}
