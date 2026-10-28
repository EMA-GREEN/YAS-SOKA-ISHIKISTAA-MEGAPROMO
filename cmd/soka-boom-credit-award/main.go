// Command soka-boom-credit-award runs one BOOM free-answer award round. It is
// meant for a scheduler (cron or a systemd timer) once the owner confirms the
// legacy schedule of BoomCustomerBaseLoad.php.
package main

import (
	"context"
	"flag"
	"os"
	"os/signal"
	"syscall"

	driver "github.com/go-sql-driver/mysql"
	"yas-modernization/internal/obs"
	mysqlstore "yas-modernization/internal/store/mysql"
	"yas-modernization/internal/workers/sokaboom"
)

func main() {
	round := flag.String("round", "", "award round name, such as 2026-10-28 (required)")
	batch := flag.Int("batch", 500, "subscriptions per transaction")
	apply := flag.Bool("apply", false, "grant credits; without it the command only checks configuration")
	flag.Parse()
	log := obs.NewLogger(os.Stdout)
	dsn := os.Getenv("MYSQL_DSN")
	cfg, err := driver.ParseDSN(dsn)
	if err != nil || cfg.DBName == "" || cfg.DBName != os.Getenv("EXPECTED_DB") {
		log.Error("database name does not match EXPECTED_DB")
		os.Exit(1)
	}
	if *round == "" {
		log.Error("-round is required")
		os.Exit(1)
	}
	store, err := mysqlstore.Open(dsn)
	if err != nil {
		log.Error("database unavailable", "error", err)
		os.Exit(1)
	}
	defer store.DB.Close()
	if !*apply {
		log.Info("dry run: pass -apply to grant credits", "round", *round)
		return
	}
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	n, err := sokaboom.Award(ctx, store.DB, *round, *batch)
	if err != nil {
		log.Error("award round stopped; rerun the same round to finish", "round", *round, "awarded", n, "error", err)
		os.Exit(1)
	}
	log.Info("award round complete", "round", *round, "awarded", n)
}
