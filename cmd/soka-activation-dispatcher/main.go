package main

import (
	"context"
	"os"
	"os/signal"
	"syscall"
	"time"

	driver "github.com/go-sql-driver/mysql"
	"yas-modernization/internal/activationdispatch"
	"yas-modernization/internal/obs"
	mysqlstore "yas-modernization/internal/store/mysql"
	"yas-modernization/internal/tigoauth"
)

func main() {
	log := obs.NewLogger(os.Stdout)
	if os.Getenv("ACTIVATION_DISPATCH_ENABLED") != "true" {
		log.Error("activation dispatch requires ACTIVATION_DISPATCH_ENABLED=true")
		os.Exit(1)
	}
	dsn := os.Getenv("MYSQL_DSN")
	cfg, err := driver.ParseDSN(dsn)
	if err != nil || cfg.DBName == "" || cfg.DBName != os.Getenv("EXPECTED_DB") {
		log.Error("database name does not match EXPECTED_DB")
		os.Exit(1)
	}
	store, err := mysqlstore.Open(dsn)
	if err != nil {
		log.Error("database unavailable", "error", err)
		os.Exit(1)
	}
	defer store.DB.Close()
	var connected string
	if err := store.DB.QueryRow(`SELECT DATABASE()`).Scan(&connected); err != nil || connected != cfg.DBName {
		log.Error("connected database mismatch")
		os.Exit(1)
	}
	tokens, err := tigoauth.FromEnv(os.Getenv, "ACTIVATION_ACCESS_TOKEN")
	if err != nil {
		log.Error("API token configuration invalid", "error", err)
		os.Exit(1)
	}
	dispatcher := activationdispatch.Dispatcher{DB: store.DB, Config: activationdispatch.Config{
		Endpoint: os.Getenv("ACTIVATION_ENDPOINT"), CPID: os.Getenv("ACTIVATION_CP_ID"),
		Password: os.Getenv("ACTIVATION_PASSWORD"), Username: os.Getenv("ACTIVATION_USERNAME"),
		Tokens: tokens, SourceAddress: os.Getenv("ACTIVATION_SOURCE_ADDRESS"),
	}}
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	reg := &obs.Registry{}
	reg.StateGaugeFunc("yas_activation_attempts", "Open and review-needed M-Push activations by state.", "state", []string{"PENDING", "SENDING", "NEEDS_REVIEW"},
		func(ctx context.Context) (map[string]float64, error) {
			return obs.CountByState(ctx, store.DB, `SELECT state, COUNT(*) FROM activation_attempts WHERE state IN ('PENDING','SENDING','NEEDS_REVIEW') GROUP BY state`)
		})
	attempts := reg.Counter("yas_dispatch_attempts_total", "Dispatch loop results: ok, uncertain (claimed, needs review), or failed_before_claim.", "dispatcher", "result")
	if err := obs.ServeOps(ctx, obs.OpsAddr("9099"), reg, store.Ready, log); err != nil {
		log.Error("ops listener unavailable", "error", err)
		os.Exit(1)
	}
	for ctx.Err() == nil {
		workCtx, cancel := context.WithTimeout(ctx, 15*time.Second)
		worked, err := dispatcher.DispatchOne(workCtx)
		cancel()
		switch {
		case worked && err == nil:
			attempts.Inc("activation", "ok")
		case worked:
			attempts.Inc("activation", "uncertain")
		case err != nil:
			attempts.Inc("activation", "failed_before_claim")
		}
		if err != nil {
			log.Error("activation result needs review", "error", err)
		}
		if worked && err == nil {
			continue
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(2 * time.Second):
		}
	}
}
