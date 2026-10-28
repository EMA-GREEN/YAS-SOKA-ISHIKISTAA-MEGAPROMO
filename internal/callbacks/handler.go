package callbacks

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"time"
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
		mux.HandleFunc("POST "+path, func(w http.ResponseWriter, r *http.Request) { h.receive(w, r, p) })
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
	defer r.Body.Close()
	r.Body = http.MaxBytesReader(w, r.Body, 1<<20)
	body, err := io.ReadAll(r.Body)
	if err != nil {
		http.Error(w, "invalid request size", http.StatusRequestEntityTooLarge)
		return
	}
	event, err := ParseJSON(product, body)
	if err != nil {
		http.Error(w, "invalid callback", http.StatusBadRequest)
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 5*time.Second)
	defer cancel()
	id, err := h.Store.Save(ctx, event)
	if errors.Is(err, ErrConflict) {
		http.Error(w, "conflicting callback", http.StatusConflict)
		return
	}
	if err != nil {
		h.Log.Error("callback persistence failed", "product", product, "error", err)
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
}
