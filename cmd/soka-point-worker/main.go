package main

import (
	"context"
	"log/slog"
	"os"
	"os/signal"
	"syscall"

	mysqlstore "yas-modernization/internal/store/mysql"
	"yas-modernization/internal/workers/sokapoints"
)

func main() {
	log := slog.New(slog.NewJSONHandler(os.Stdout, nil))
	store, err := mysqlstore.Open(os.Getenv("MYSQL_DSN"))
	if err != nil {
		log.Error("database unavailable", "error", err)
		os.Exit(1)
	}
	defer store.DB.Close()
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	consumer := sokapoints.Consumer{
		Processor: sokapoints.Processor{DB: store.DB},
		AMQPURL:   os.Getenv("AMQP_URL"),
		Log:       log,
	}
	if err := consumer.Run(ctx); err != nil {
		log.Error("worker stopped", "error", err)
		os.Exit(1)
	}
}
