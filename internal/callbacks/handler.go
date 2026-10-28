package callbacks

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"time"

	"yas-modernization/internal/obs"
)

var ErrConflict = errors.New("request ID reused with different payload")

type Store interface {
	Save(context.Context, Event) (int64, error)
	Ready(context.Context) error
}

type Handler struct {
	Store    Store
	Log      *slog.Logger
	Location *time.Location
	// Guard, when set, wraps every callback route but not the health routes.
	Guard   func(http.Handler) http.Handler
	Metrics *Metrics
}

// Metrics counts callback outcomes by product. Outcomes are a fixed set.
type Metrics struct {
	Requests *obs.CounterVec
	Duration *obs.HistogramVec
}

func NewMetrics(reg *obs.Registry) *Metrics {
	return &Metrics{
		Requests: reg.Counter("yas_callback_requests_total", "Callback requests by product and outcome.", "product", "outcome"),
		Duration: reg.Histogram("yas_callback_duration_seconds", "Callback handling time by product.",
			[]float64{0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5}, "product"),
	}
}

func (m *Metrics) observe(product, outcome string, started time.Time) {
	if m == nil {
		return
	}
	m.Requests.Inc(product, outcome)
	m.Duration.Observe(time.Since(started).Seconds(), product)
}

func (h Handler) Routes() http.Handler {
	mux := http.NewServeMux()
	for path, product := range map[string]string{
		"/notification/soka-trivia/billing": "soka",
		"/soka-trivia/notification":         "soka",
		"/ishikistaa/notification":          "ishikistaa",
		"/notification/megapromo":           "megapromo",
	} {
		p := product
		var route http.Handler = http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { h.receive(w, r, p) })
		if h.Guard != nil {
			route = h.Guard(route)
		}
		mux.Handle("POST "+path, route)
	}
	guarded := func(f http.HandlerFunc) http.Handler {
		if h.Guard != nil {
			return h.Guard(f)
		}
		return f
	}
	for path, product := range map[string]string{
		"/soka-mpush/notification":       "soka",
		"/ishikistaa-mpush/notification": "ishikistaa",
		"/megapromo-mpush/notification":  "megapromo",
	} {
		p := product
		mux.Handle("POST "+path, guarded(func(w http.ResponseWriter, r *http.Request) { h.receiveMpush(w, r, p) }))
	}
	// The XML tigopesa/collection route and the test routes are not ported; see
	// docs/legacy-process-matrix.md.
	for _, path := range []string{"/notification/megapromo/tigopesapush/collection", "/notification/megapromo/mixxpush/collection"} {
		mux.Handle("POST "+path, guarded(h.receiveTigoPesaPush))
	}
	mux.HandleFunc("GET /healthz", func(w http.ResponseWriter, _ *http.Request) { w.WriteHeader(http.StatusNoContent) })
	mux.HandleFunc("GET /readyz", func(w http.ResponseWriter, r *http.Request) {
		ctx, cancel := context.WithTimeout(r.Context(), 2*time.Second)
		defer cancel()
		if err := h.Store.Ready(ctx); err != nil {
			http.Error(w, "unavailable", http.StatusServiceUnavailable)
			return
		}
		w.WriteHeader(http.StatusNoContent)
	})
	return mux
}

func (h Handler) receive(w http.ResponseWriter, r *http.Request, product string) {
	started := time.Now()
	defer r.Body.Close()
	r.Body = http.MaxBytesReader(w, r.Body, 1<<20)
	body, err := io.ReadAll(r.Body)
	if err != nil {
		h.Metrics.observe(product, "too_large", started)
		http.Error(w, "invalid request size", http.StatusRequestEntityTooLarge)
		return
	}
	event, err := ParseJSON(product, body)
	if err != nil {
		h.Metrics.observe(product, "invalid", started)
		http.Error(w, "invalid callback", http.StatusBadRequest)
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 5*time.Second)
	defer cancel()
	id, err := h.Store.Save(ctx, event)
	if errors.Is(err, ErrConflict) {
		h.Log.Warn("callback request ID reused with different payload", "product", product, "request_id", event.RequestID)
		h.Metrics.observe(product, "conflict", started)
		http.Error(w, "conflicting callback", http.StatusConflict)
		return
	}
	if err != nil {
		h.Log.Error("callback persistence failed", "product", product, "error", err)
		h.Metrics.observe(product, "unavailable", started)
		http.Error(w, "temporarily unavailable", http.StatusServiceUnavailable)
		return
	}
	stamp := time.Now().In(h.Location).Format("20060102150405")
	response := struct {
		RequestID         string `json:"requestId"`
		ResponseID        int64  `json:"responseId"`
		ResponseTimeStamp string `json:"responseTimeStamp"`
		Operation         string `json:"operation"`
		ResponseParam     struct {
			Status      string `json:"status"`
			StatusCode  string `json:"statusCode"`
			Description string `json:"description"`
		} `json:"responseParam"`
	}{RequestID: event.RequestID, ResponseID: id, ResponseTimeStamp: stamp, Operation: "CP_NOTIFICATION"}
	response.ResponseParam.Status = "0"
	response.ResponseParam.StatusCode = "0000"
	response.ResponseParam.Description = "Success"
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(response)
	h.Metrics.observe(product, "accepted", started)
}
