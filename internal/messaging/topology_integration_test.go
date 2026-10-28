package messaging

import (
	"context"
	"os"
	"testing"
	"time"

	amqp "github.com/rabbitmq/amqp091-go"
)

func broker(t *testing.T) (*amqp.Connection, *amqp.Channel) {
	t.Helper()
	url := os.Getenv("TEST_AMQP_URL")
	if url == "" {
		t.Skip("set TEST_AMQP_URL for isolated RabbitMQ integration test")
	}
	conn, err := amqp.Dial(url)
	if err != nil {
		t.Fatal(err)
	}
	ch, err := conn.Channel()
	if err != nil {
		conn.Close()
		t.Fatal(err)
	}
	t.Cleanup(func() { ch.Close(); conn.Close() })
	return conn, ch
}

func publishConfirmed(t *testing.T, conn *amqp.Connection, exchange, routing, id string, mandatory bool) bool {
	t.Helper()
	ch, err := conn.Channel()
	if err != nil {
		t.Fatal(err)
	}
	defer ch.Close()
	if err := ch.Confirm(false); err != nil {
		t.Fatal(err)
	}
	confirms := ch.NotifyPublish(make(chan amqp.Confirmation, 1))
	returns := ch.NotifyReturn(make(chan amqp.Return, 1))
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := ch.PublishWithContext(ctx, exchange, routing, mandatory, false, amqp.Publishing{ContentType: "application/json", DeliveryMode: amqp.Persistent, MessageId: id, Body: []byte(`{"test":true}`)}); err != nil {
		t.Fatal(err)
	}
	select {
	case c := <-confirms:
		if !c.Ack {
			t.Fatal("publish not confirmed")
		}
	case <-ctx.Done():
		t.Fatal(ctx.Err())
	}
	select {
	case <-returns:
		return true
	case <-time.After(100 * time.Millisecond):
		return false
	}
}

func receive(t *testing.T, ch *amqp.Channel, queue string) amqp.Delivery {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		d, ok, err := ch.Get(queue, false)
		if err != nil {
			t.Fatal(err)
		}
		if ok {
			return d
		}
		time.Sleep(25 * time.Millisecond)
	}
	t.Fatalf("no delivery on %s", queue)
	return amqp.Delivery{}
}

func TestBrokerRoutingAndRecovery(t *testing.T) {
	conn, ch := broker(t)
	if err := ch.ExchangeDeclare("yas.callbacks", "topic", true, false, false, false, nil); err != nil {
		t.Fatal(err)
	}
	if err := DeclareSokaPointPurchases(ch); err != nil {
		t.Fatal(err)
	}
	if err := DeclareSokaMO(ch); err != nil {
		t.Fatal(err)
	}
	if err := DeclareParked(ch); err != nil {
		t.Fatal(err)
	}
	for _, q := range []string{"yas.soka.point-purchases", "yas.soka.point-purchases.dead", "yas.soka.point-mo", "yas.soka.point-mo.dead", "yas.soka.pending", "yas.megapromo.pending"} {
		if _, err := ch.QueuePurge(q, false); err != nil {
			t.Fatal(err)
		}
	}
	if returned := publishConfirmed(t, conn, "yas.callbacks", "soka.Payment", "101", true); returned {
		t.Fatal("routed payment returned")
	}
	d := receive(t, ch, "yas.soka.point-purchases")
	if d.MessageId != "101" {
		t.Fatalf("payment message ID = %q", d.MessageId)
	}
	if err := d.Nack(false, false); err != nil {
		t.Fatal(err)
	}
	dead := receive(t, ch, "yas.soka.point-purchases.dead")
	if dead.MessageId != "101" {
		t.Fatalf("dead-letter message ID = %q", dead.MessageId)
	}
	if err := dead.Ack(false); err != nil {
		t.Fatal(err)
	}
	if returned := publishConfirmed(t, conn, "yas.callbacks", "soka.InteractiveResponse", "102", true); returned {
		t.Fatal("routed MO returned")
	}
	mo := receive(t, ch, "yas.soka.point-mo")
	if mo.MessageId != "102" {
		t.Fatalf("MO message ID = %q", mo.MessageId)
	}
	if err := mo.Ack(false); err != nil {
		t.Fatal(err)
	}
	if returned := publishConfirmed(t, conn, "yas.callbacks", "unmatched.IntegrationTest", "103", true); !returned {
		t.Fatal("mandatory unroutable message was not returned")
	}
	// A Soka kind with no worker and no parking binding must also be returned.
	if returned := publishConfirmed(t, conn, "yas.callbacks", "soka.UnknownKind", "105", true); !returned {
		t.Fatal("unknown Soka kind was not returned")
	}
	// Kinds received before their workers exist are parked, not dropped.
	for key, queue := range map[string]string{"megapromo.TigoPesaPushResult": "yas.megapromo.pending", "soka.MpushSubsInit": "yas.soka.pending"} {
		if returned := publishConfirmed(t, conn, "yas.callbacks", key, "106", true); returned {
			t.Fatalf("%s returned", key)
		}
		if d := receive(t, ch, queue); d.MessageId != "106" {
			t.Fatalf("%s not parked in %s", key, queue)
		} else if err := d.Ack(false); err != nil {
			t.Fatal(err)
		}
	}
	if returned := publishConfirmed(t, conn, "yas.callbacks", "soka.Payment", "104", true); returned {
		t.Fatal("routed replay test returned")
	}
	consumer, err := conn.Channel()
	if err != nil {
		t.Fatal(err)
	}
	unacked := receive(t, consumer, "yas.soka.point-purchases")
	if unacked.MessageId != "104" {
		t.Fatalf("unacked message ID = %q", unacked.MessageId)
	}
	if err := consumer.Close(); err != nil {
		t.Fatal(err)
	}
	replay := receive(t, ch, "yas.soka.point-purchases")
	if replay.MessageId != "104" || !replay.Redelivered {
		t.Fatalf("expected redelivery of 104; got %q, redelivered=%v", replay.MessageId, replay.Redelivered)
	}
	if err := replay.Ack(false); err != nil {
		t.Fatal(err)
	}
}

func TestBrokerRestartPersistence(t *testing.T) {
	conn, ch := broker(t)
	phase := os.Getenv("TEST_BROKER_PHASE")
	if phase == "" {
		t.Skip("set TEST_BROKER_PHASE=seed or check around broker restart")
	}
	if _, err := ch.QueueDeclare("yas.integration.persist", true, false, false, false, nil); err != nil {
		t.Fatal(err)
	}
	switch phase {
	case "seed":
		if _, err := ch.QueuePurge("yas.integration.persist", false); err != nil {
			t.Fatal(err)
		}
		if returned := publishConfirmed(t, conn, "", "yas.integration.persist", "restart-1", true); returned {
			t.Fatal("persistent message returned")
		}
	case "check":
		d := receive(t, ch, "yas.integration.persist")
		if d.MessageId != "restart-1" {
			t.Fatalf("message after broker restart = %q", d.MessageId)
		}
		if err := d.Ack(false); err != nil {
			t.Fatal(err)
		}
	default:
		t.Fatalf("unknown phase %q", phase)
	}
}
