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
	return ch.QueueBind("yas.soka.point-mo", "soka.InteractiveResponse", "yas.callbacks", false, nil)
}
