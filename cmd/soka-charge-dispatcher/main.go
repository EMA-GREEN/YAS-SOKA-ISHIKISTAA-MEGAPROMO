package main

import (
	"context"
	"os"
	"os/signal"
	"syscall"
	"time"

	driver "github.com/go-sql-driver/mysql"
	"yas-modernization/internal/chargedispatch"
	"yas-modernization/internal/obs"
	mysqlstore "yas-modernization/internal/store/mysql"
)

func main() {
	log := obs.NewLogger(os.Stdout)
	if os.Getenv("CHARGE_DISPATCH_ENABLED") != "true" {
		log.Error("charge dispatch requires CHARGE_DISPATCH_ENABLED=true")
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
	dispatcher := chargedispatch.Dispatcher{DB: store.DB, Config: chargedispatch.Config{
		Endpoint: os.Getenv("CHARGE_ENDPOINT"), CPID: os.Getenv("CHARGE_CP_ID"),
		Password: os.Getenv("CHARGE_PASSWORD"), Username: os.Getenv("CHARGE_USERNAME"),
		BearerToken: os.Getenv("CHARGE_BEARER_TOKEN"), SourceAddress: os.Getenv("CHARGE_SOURCE_ADDRESS"),
	}}
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	for ctx.Err() == nil {
		workCtx, cancel := context.WithTimeout(ctx, 15*time.Second)
		worked, err := dispatcher.DispatchOne(workCtx)
		cancel()
		if err != nil {
			log.Error("charge result needs review", "error", err)
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
