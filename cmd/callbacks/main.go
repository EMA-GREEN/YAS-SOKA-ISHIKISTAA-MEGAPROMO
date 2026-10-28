package main

import (
	"context"
	"errors"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"yas-modernization/internal/callbacks"
	"yas-modernization/internal/ingress"
	"yas-modernization/internal/obs"
	"yas-modernization/internal/relay"
	mysqlstore "yas-modernization/internal/store/mysql"
)

func main() {
	log := obs.NewLogger(os.Stdout)
	policy, err := ingress.FromEnv(os.Getenv)
	if err != nil {
		log.Error("invalid ingress configuration", "error", err)
		os.Exit(1)
	}
	if policy.Mode == ingress.Off {
		log.Warn("ingress policy is off; any client can submit callbacks")
	}
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
	// Metrics stay off the public listener; the default binds to loopback only.
	opsAddr := os.Getenv("OPS_ADDR")
	if opsAddr == "" {
		opsAddr = "127.0.0.1:9091"
	}
	reg := &obs.Registry{}
	outbox := relay.Relay{DB: store.DB}
	reg.GaugeFunc("yas_outbox_backlog", "Outbox rows not yet published.", func(ctx context.Context) (float64, error) {
		n, err := outbox.Backlog(ctx)
		return float64(n), err
	})
	reg.GaugeFunc("yas_outbox_dead_letters", "Outbox rows that exhausted publish attempts.", func(ctx context.Context) (float64, error) {
		n, err := outbox.DeadLetters(ctx)
		return float64(n), err
	})
	handler := callbacks.Handler{Store: store, Log: log, Location: location,
		Guard: ingress.NewGuard(policy, log, reg).Wrap, Metrics: callbacks.NewMetrics(reg)}
	public := handler.Routes()
	ops := http.NewServeMux()
	ops.Handle("GET /metrics", reg.Handler())
	ops.Handle("GET /healthz", public)
	ops.Handle("GET /readyz", public)
	servers := []*http.Server{
		{Addr: addr, Handler: public, ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 10 * time.Second, WriteTimeout: 10 * time.Second, IdleTimeout: 60 * time.Second, MaxHeaderBytes: 16 << 10},
		{Addr: opsAddr, Handler: ops, ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 10 * time.Second, WriteTimeout: 10 * time.Second, IdleTimeout: 60 * time.Second, MaxHeaderBytes: 16 << 10},
	}
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()
	failed := make(chan error, len(servers))
	for _, server := range servers {
		go func() {
			if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
				failed <- err
			}
		}()
	}
	log.Info("callback server listening", "address", addr, "ops_address", opsAddr, "ingress_mode", string(policy.Mode),
		"allowed_networks", len(policy.Allowed), "token_required", policy.HasToken())
	exit := 0
	select {
	case <-ctx.Done():
	case err := <-failed:
		log.Error("server stopped", "error", err)
		exit = 1
	}
	shutdown, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()
	for _, server := range servers {
		_ = server.Shutdown(shutdown)
	}
	if exit != 0 {
		os.Exit(exit)
	}
}
