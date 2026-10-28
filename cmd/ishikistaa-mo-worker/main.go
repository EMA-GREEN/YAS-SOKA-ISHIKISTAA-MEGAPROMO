// Command ishikistaa-mo-worker runs the shared trivia MO engine with the
// Ishikistaa profile; see docs/ishikistaa-differences.md.
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
	"yas-modernization/internal/workers/sokamo"
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
	consumer := sokamo.Consumer{
		Processor: sokamo.Processor{DB: store.DB, Profile: sokagame.Ishikistaa},
		Queue:     "yas.ishikistaa.mo",
		Declare:   messaging.DeclareIshikistaaMO,
		Name:      sokagame.Ishikistaa.MOConsumer,
		AMQPURL:   os.Getenv("AMQP_URL"),
		Log:       log,
		Metrics:   messaging.NewConsumerMetrics(reg),
	}
	if err := obs.ServeOps(ctx, obs.OpsAddr("9100"), reg, store.Ready, log); err != nil {
		log.Error("ops listener unavailable", "error", err)
		os.Exit(1)
	}
	if err := consumer.Run(ctx); err != nil {
		log.Error("worker stopped", "error", err)
		os.Exit(1)
	}
}
