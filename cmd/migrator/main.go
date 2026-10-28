package main

import (
	"context"
	"flag"
	"fmt"
	"os"
	"time"

	mysql "github.com/go-sql-driver/mysql"
	"yas-modernization/internal/migrate"
	mysqlstore "yas-modernization/internal/store/mysql"
)

func main() {
	dir := flag.String("dir", "migrations", "directory of numbered SQL files")
	apply := flag.Bool("apply", false, "execute migrations after explicit target validation")
	expected := flag.String("expected-db", "", "exact target database name (required with -apply)")
	flag.Parse()
	files, err := migrate.Load(*dir)
	if err != nil {
		fail(err)
	}
	for _, file := range files {
		fmt.Printf("%s sha256=%x statements=%d\n", file.Name, file.Hash, len(file.Statements))
	}
	if !*apply {
		fmt.Println("plan only; no database connection or changes")
		return
	}
	dsn := os.Getenv("MIGRATION_DB_DSN")
	if dsn == "" || *expected == "" {
		fail(fmt.Errorf("MIGRATION_DB_DSN and -expected-db are required"))
	}
	cfg, err := mysql.ParseDSN(dsn)
	if err != nil {
		fail(fmt.Errorf("invalid MIGRATION_DB_DSN"))
	}
	if cfg.DBName != *expected {
		fail(fmt.Errorf("target database does not match -expected-db"))
	}
	store, err := mysqlstore.Open(dsn)
	if err != nil {
		fail(err)
	}
	defer store.DB.Close()
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Minute)
	defer cancel()
	if err := migrate.Apply(ctx, store.DB, files); err != nil {
		fail(err)
	}
	fmt.Println("migrations applied")
}

func fail(err error) { fmt.Fprintln(os.Stderr, err); os.Exit(1) }
