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
	reg := &obs.Registry{}
	r := relay.Relay{DB: store.DB, AMQPURL: os.Getenv("AMQP_URL"), Log: log, Metrics: relay.NewMetrics(reg)}
	registerOutboxGauges(reg, r)
	if err := obs.ServeOps(ctx, obs.OpsAddr("9092"), reg, store.Ready, log); err != nil {
		log.Error("ops listener unavailable", "error", err)
		os.Exit(1)
	}
	if err := r.Run(ctx); err != nil {
		log.Error("relay stopped", "error", err)
		os.Exit(1)
	}
}

func registerOutboxGauges(reg *obs.Registry, r relay.Relay) {
	reg.GaugeFunc("yas_outbox_backlog", "Outbox rows not yet published.", func(ctx context.Context) (float64, error) {
		n, err := r.Backlog(ctx)
		return float64(n), err
	})
	reg.GaugeFunc("yas_outbox_dead_letters", "Outbox rows that exhausted publish attempts.", func(ctx context.Context) (float64, error) {
		n, err := r.DeadLetters(ctx)
		return float64(n), err
	})
	reg.GaugeFunc("yas_outbox_oldest_pending_seconds", "Age of the oldest unpublished outbox row.", r.OldestPendingAge)
}
