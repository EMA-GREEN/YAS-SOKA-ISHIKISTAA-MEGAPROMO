package main

import (
	"context"
	"os"
	"os/signal"
	"syscall"

	"yas-modernization/internal/obs"
	"yas-modernization/internal/relay"
	mysqlstore "yas-modernization/internal/store/mysql"
)

func main() {
	log := obs.NewLogger(os.Stdout)
	store, err := mysqlstore.Open(os.Getenv("MYSQL_DSN"))
	if err != nil {
		log.Error("startup failed", "error", err)
		os.Exit(1)
	}
	defer store.DB.Close()
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()
	if err := (relay.Relay{DB: store.DB, AMQPURL: os.Getenv("AMQP_URL"), Log: log}).Run(ctx); err != nil {
		log.Error("relay stopped", "error", err)
		os.Exit(1)
	}
}
