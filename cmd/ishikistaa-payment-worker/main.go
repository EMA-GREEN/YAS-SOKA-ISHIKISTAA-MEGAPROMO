// Command ishikistaa-payment-worker runs the shared trivia payment engine
// with the Ishikistaa profile.
package main

import (
	"context"
	"os"
	"os/signal"
	"syscall"

	"yas-modernization/internal/messaging"
	"yas-modernization/internal/obs"
	mysqlstore "yas-modernization/internal/store/mysql"
	"yas-modernization/internal/workers/sokagame"
	"yas-modernization/internal/workers/sokapoints"
)

func main() {
	log := obs.NewLogger(os.Stdout)
	store, err := mysqlstore.Open(os.Getenv("MYSQL_DSN"))
	if err != nil {
		log.Error("database unavailable", "error", err)
		os.Exit(1)
	}
	defer store.DB.Close()
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	reg := &obs.Registry{}
	consumer := sokapoints.Consumer{
		Processor: sokapoints.Processor{DB: store.DB, Profile: sokagame.Ishikistaa},
		Queue:     "yas.ishikistaa.payments",
		Declare:   messaging.DeclareIshikistaaPayments,
		Name:      sokagame.Ishikistaa.PaymentConsumer,
		AMQPURL:   os.Getenv("AMQP_URL"),
		Log:       log,
		Metrics:   messaging.NewConsumerMetrics(reg),
	}
	if err := obs.ServeOps(ctx, obs.OpsAddr("9101"), reg, store.Ready, log); err != nil {
		log.Error("ops listener unavailable", "error", err)
		os.Exit(1)
	}
	if err := consumer.Run(ctx); err != nil {
		log.Error("worker stopped", "error", err)
		os.Exit(1)
	}
}
