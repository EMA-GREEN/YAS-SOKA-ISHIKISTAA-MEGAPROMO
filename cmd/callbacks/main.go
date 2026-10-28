package main

import (
	"context"
	"errors"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"yas-modernization/internal/callbacks"
	mysqlstore "yas-modernization/internal/store/mysql"
)

func main() {
	log := slog.New(slog.NewJSONHandler(os.Stdout, nil))
	store, err := mysqlstore.Open(os.Getenv("MYSQL_DSN"))
	if err != nil {
		log.Error("startup failed", "error", err)
		os.Exit(1)
	}
	defer store.DB.Close()
	location, err := time.LoadLocation("Africa/Dar_es_Salaam")
	if err != nil {
		log.Error("timezone unavailable", "error", err)
		os.Exit(1)
	}
	addr := os.Getenv("HTTP_ADDR")
	if addr == "" {
		addr = ":8080"
	}
	server := &http.Server{Addr: addr, Handler: callbacks.Handler{Store: store, Log: log, Location: location}.Routes(), ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 10 * time.Second, WriteTimeout: 10 * time.Second, IdleTimeout: 60 * time.Second}
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()
	go func() {
		<-ctx.Done()
		shutdown, cancel := context.WithTimeout(context.Background(), 15*time.Second)
		defer cancel()
		_ = server.Shutdown(shutdown)
	}()
	log.Info("callback server listening", "address", addr)
	if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		log.Error("server stopped", "error", err)
		os.Exit(1)
	}
}
