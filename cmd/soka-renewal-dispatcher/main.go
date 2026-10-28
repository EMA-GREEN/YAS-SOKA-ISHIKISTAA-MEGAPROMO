package main

import (
	"context"
	"os"
	"os/signal"
	"syscall"
	"time"

	driver "github.com/go-sql-driver/mysql"
	"yas-modernization/internal/obs"
	"yas-modernization/internal/renewaldispatch"
	mysqlstore "yas-modernization/internal/store/mysql"
)

func main() {
	log := obs.NewLogger(os.Stdout)
	if os.Getenv("RENEWAL_DISPATCH_ENABLED") != "true" {
		log.Error("renewal dispatch requires RENEWAL_DISPATCH_ENABLED=true")
		os.Exit(1)
	}
	dsn := os.Getenv("MYSQL_DSN")
	cfg, err := driver.ParseDSN(dsn)
	if err != nil || cfg.DBName == "" || cfg.DBName != os.Getenv("EXPECTED_DB") {
		log.Error("database name does not match EXPECTED_DB")
		os.Exit(1)
	}
	// Only configured targets are claimed; the rest stay PENDING for later.
	endpoints := map[string]string{}
	for target, env := range map[string]string{"MANAGE_SUBSCRIPTION": "RENEWAL_MANAGE_SUBSCRIPTION_URL", "SUBS_MANAGE": "RENEWAL_SUBS_MANAGE_URL"} {
		if v := os.Getenv(env); v != "" {
			endpoints[target] = v
		}
	}
	if len(endpoints) == 0 {
		log.Error("set RENEWAL_MANAGE_SUBSCRIPTION_URL and/or RENEWAL_SUBS_MANAGE_URL")
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
	dispatcher := renewaldispatch.Dispatcher{DB: store.DB, Config: renewaldispatch.Config{Endpoints: endpoints}}
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	reg := &obs.Registry{}
	reg.StateGaugeFunc("yas_renewal_requests", "Open and review-needed renewal-system requests by state.", "state", []string{"PENDING", "SENDING", "NEEDS_REVIEW"},
		func(ctx context.Context) (map[string]float64, error) {
			return obs.CountByState(ctx, store.DB, `SELECT state, COUNT(*) FROM renewal_requests WHERE state IN ('PENDING','SENDING','NEEDS_REVIEW') GROUP BY state`)
		})
	attempts := reg.Counter("yas_dispatch_attempts_total", "Dispatch loop results: ok, uncertain (claimed, needs review), or failed_before_claim.", "dispatcher", "result")
	if err := obs.ServeOps(ctx, obs.OpsAddr("9097"), reg, store.Ready, log); err != nil {
		log.Error("ops listener unavailable", "error", err)
		os.Exit(1)
	}
	for ctx.Err() == nil {
		workCtx, cancel := context.WithTimeout(ctx, 15*time.Second)
		worked, err := dispatcher.DispatchOne(workCtx)
		cancel()
		switch {
		case worked && err == nil:
			attempts.Inc("renewal", "ok")
		case worked:
			attempts.Inc("renewal", "uncertain")
		case err != nil:
			attempts.Inc("renewal", "failed_before_claim")
		}
		if err != nil {
			log.Error("renewal result needs review", "error", err)
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
