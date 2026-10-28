package obs

import (
	"context"
	"database/sql"
	"log/slog"
	"net"
	"net/http"
	"os"
	"time"
)

// OpsAddr returns OPS_ADDR, or a loopback default that differs per process so
// several processes can share a host.
func OpsAddr(defaultPort string) string {
	if addr := os.Getenv("OPS_ADDR"); addr != "" {
		return addr
	}
	return "127.0.0.1:" + defaultPort
}

// ServeOps binds the metrics and health listener before returning, so a port
// conflict stops startup instead of leaving the process unobservable. The
// listener closes when ctx ends.
func ServeOps(ctx context.Context, addr string, reg *Registry, ready func(context.Context) error, log *slog.Logger) error {
	listener, err := net.Listen("tcp", addr)
	if err != nil {
		return err
	}
	mux := http.NewServeMux()
	mux.Handle("GET /metrics", reg.Handler())
	mux.HandleFunc("GET /healthz", func(w http.ResponseWriter, _ *http.Request) { w.WriteHeader(http.StatusNoContent) })
	mux.HandleFunc("GET /readyz", func(w http.ResponseWriter, r *http.Request) {
		ctx, cancel := context.WithTimeout(r.Context(), 2*time.Second)
		defer cancel()
		if err := ready(ctx); err != nil {
			http.Error(w, "unavailable", http.StatusServiceUnavailable)
			return
		}
		w.WriteHeader(http.StatusNoContent)
	})
	server := &http.Server{Handler: mux, ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 10 * time.Second,
		WriteTimeout: 10 * time.Second, IdleTimeout: 60 * time.Second, MaxHeaderBytes: 16 << 10}
	go func() {
		if err := server.Serve(listener); err != nil && err != http.ErrServerClosed {
			log.Error("ops listener stopped", "error", err)
		}
	}()
	go func() {
		<-ctx.Done()
		shutdown, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		_ = server.Shutdown(shutdown)
	}()
	log.Info("ops listener ready", "ops_address", listener.Addr().String())
	return nil
}

// CountByState scans rows of (state, count) into a map for StateGaugeFunc.
func CountByState(ctx context.Context, db *sql.DB, query string) (map[string]float64, error) {
	rows, err := db.QueryContext(ctx, query)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	out := map[string]float64{}
	for rows.Next() {
		var state string
		var n float64
		if err := rows.Scan(&state, &n); err != nil {
			return nil, err
		}
		out[state] = n
	}
	return out, rows.Err()
}
