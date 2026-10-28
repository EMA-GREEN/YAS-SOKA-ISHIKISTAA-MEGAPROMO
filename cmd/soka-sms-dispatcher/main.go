package main

import (
	"context"
	"os"
	"os/signal"
	"syscall"
	"time"

	driver "github.com/go-sql-driver/mysql"
	"yas-modernization/internal/obs"
	"yas-modernization/internal/smsdispatch"
	mysqlstore "yas-modernization/internal/store/mysql"
)

func main() {
	log := obs.NewLogger(os.Stdout)
	if os.Getenv("SMS_DISPATCH_ENABLED") != "true" {
		log.Error("SMS dispatch requires SMS_DISPATCH_ENABLED=true")
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
	dispatcher := smsdispatch.Dispatcher{DB: store.DB, Config: smsdispatch.Config{
		Endpoint: os.Getenv("SMS_ENDPOINT"), CPID: os.Getenv("SMS_CP_ID"),
		Password: os.Getenv("SMS_PASSWORD"), Username: os.Getenv("SMS_USERNAME"),
		BearerToken: os.Getenv("SMS_BEARER_TOKEN"), SourceAddress: os.Getenv("SMS_SOURCE_ADDRESS"),
	}}
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	reg := &obs.Registry{}
	// Terminal states grow without bound, so only open and review states are counted.
	reg.StateGaugeFunc("yas_outbound_messages", "Open and review-needed rows by state.", "state", []string{"PENDING", "SENDING", "NEEDS_REVIEW"},
		func(ctx context.Context) (map[string]float64, error) {
			return obs.CountByState(ctx, store.DB, `SELECT delivery_state, COUNT(*) FROM outbound_messages WHERE delivery_state IN ('PENDING','SENDING','NEEDS_REVIEW') GROUP BY delivery_state`)
		})
	attempts := reg.Counter("yas_dispatch_attempts_total", "Dispatch loop results: ok, uncertain (claimed, needs review), or failed_before_claim.", "dispatcher", "result")
	if err := obs.ServeOps(ctx, obs.OpsAddr("9096"), reg, store.Ready, log); err != nil {
		log.Error("ops listener unavailable", "error", err)
		os.Exit(1)
	}
	for ctx.Err() == nil {
		workCtx, cancel := context.WithTimeout(ctx, 15*time.Second)
		worked, err := dispatcher.DispatchOne(workCtx)
		cancel()
		switch {
		case worked && err == nil:
			attempts.Inc("sms", "ok")
		case worked:
			attempts.Inc("sms", "uncertain")
		case err != nil:
			attempts.Inc("sms", "failed_before_claim")
		}
		if err != nil {
			log.Error("SMS result needs review", "error", err)
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
