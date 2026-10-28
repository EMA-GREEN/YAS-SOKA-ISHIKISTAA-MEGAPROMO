package main

import (
	"context"
	"os"
	"os/signal"
	"syscall"
	"time"

	"yas-modernization/internal/obs"
	mysqlstore "yas-modernization/internal/store/mysql"
	"yas-modernization/internal/workers/sokaopen"
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
	deliveries := reg.Counter("yas_open_question_deliveries_total", "Open-question SMS intents created by the scheduler.")
	failures := reg.Counter("yas_open_question_scheduler_failures_total", "Scheduler passes that stopped on an error and will resume.")
	reg.StateGaugeFunc("yas_question_schedules", "Soka open-question schedules not yet processed.", "state", []string{"PENDING", "PROCESSING"},
		func(ctx context.Context) (map[string]float64, error) {
			return obs.CountByState(ctx, store.DB, `SELECT state, COUNT(*) FROM question_schedules WHERE state IN ('PENDING','PROCESSING') GROUP BY state`)
		})
	if err := obs.ServeOps(ctx, obs.OpsAddr("9098"), reg, store.Ready, log); err != nil {
		log.Error("ops listener unavailable", "error", err)
		os.Exit(1)
	}
	scheduler := sokaopen.Scheduler{DB: store.DB}
	// PHP polls every 5 seconds.
	for ctx.Err() == nil {
		n, err := scheduler.RunOnce(ctx)
		deliveries.Add(float64(n))
		if err != nil && ctx.Err() == nil {
			failures.Inc()
			log.Error("open-question scheduling interrupted", "error", err)
		}
		select {
		case <-ctx.Done():
		case <-time.After(5 * time.Second):
		}
	}
}
