package obs

import (
	"context"
	"fmt"
	"io"
	"math"
	"net/http"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"
)

// Registry is a small Prometheus text-format registry. Label values must come
// from a fixed set chosen by the code, never from request input.
type Registry struct {
	mu      sync.Mutex
	metrics []metric
}

type metric interface {
	write(io.Writer)
}

func (r *Registry) add(m metric) {
	r.mu.Lock()
	r.metrics = append(r.metrics, m)
	r.mu.Unlock()
}

// Handler serves the registry in Prometheus text exposition format.
func (r *Registry) Handler() http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "text/plain; version=0.0.4; charset=utf-8")
		r.mu.Lock()
		metrics := append([]metric(nil), r.metrics...)
		r.mu.Unlock()
		for _, m := range metrics {
			m.write(w)
		}
	})
}

type CounterVec struct {
	name, help string
	labels     []string
	mu         sync.Mutex
	values     map[string]float64
}

func (r *Registry) Counter(name, help string, labels ...string) *CounterVec {
	c := &CounterVec{name: name, help: help, labels: labels, values: map[string]float64{}}
	r.add(c)
	return c
}

func (c *CounterVec) Inc(values ...string) {
	key := labelKey(c.labels, values)
	c.mu.Lock()
	c.values[key]++
	c.mu.Unlock()
}

func (c *CounterVec) Value(values ...string) float64 {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.values[labelKey(c.labels, values)]
}

func (c *CounterVec) write(w io.Writer) {
	fmt.Fprintf(w, "# HELP %s %s\n# TYPE %s counter\n", c.name, c.help, c.name)
	c.mu.Lock()
	defer c.mu.Unlock()
	for _, key := range sortedKeys(c.values) {
		fmt.Fprintf(w, "%s%s %s\n", c.name, key, formatFloat(c.values[key]))
	}
}

type HistogramVec struct {
	name, help string
	labels     []string
	buckets    []float64
	mu         sync.Mutex
	series     map[string]*histogram
}

type histogram struct {
	counts []uint64
	sum    float64
	total  uint64
}

func (r *Registry) Histogram(name, help string, buckets []float64, labels ...string) *HistogramVec {
	h := &HistogramVec{name: name, help: help, labels: labels, buckets: buckets, series: map[string]*histogram{}}
	r.add(h)
	return h
}

func (h *HistogramVec) Observe(v float64, values ...string) {
	key := labelKey(h.labels, values)
	h.mu.Lock()
	defer h.mu.Unlock()
	s := h.series[key]
	if s == nil {
		s = &histogram{counts: make([]uint64, len(h.buckets))}
		h.series[key] = s
	}
	for i, upper := range h.buckets {
		if v <= upper {
			s.counts[i]++
		}
	}
	s.sum += v
	s.total++
}

func (h *HistogramVec) write(w io.Writer) {
	fmt.Fprintf(w, "# HELP %s %s\n# TYPE %s histogram\n", h.name, h.help, h.name)
	h.mu.Lock()
	defer h.mu.Unlock()
	for _, key := range sortedKeys(h.series) {
		s := h.series[key]
		for i, upper := range h.buckets {
			fmt.Fprintf(w, "%s_bucket%s %d\n", h.name, withLabel(key, "le", formatFloat(upper)), s.counts[i])
		}
		fmt.Fprintf(w, "%s_bucket%s %d\n", h.name, withLabel(key, "le", "+Inf"), s.total)
		fmt.Fprintf(w, "%s_sum%s %s\n%s_count%s %d\n", h.name, key, formatFloat(s.sum), h.name, key, s.total)
	}
}

type gaugeFunc struct {
	name, help string
	read       func(context.Context) (float64, error)
}

// GaugeFunc reads its value at scrape time. A failed read omits the sample, so
// alerts see missing data instead of a false zero.
func (r *Registry) GaugeFunc(name, help string, read func(context.Context) (float64, error)) {
	r.add(&gaugeFunc{name: name, help: help, read: read})
}

func (g *gaugeFunc) write(w io.Writer) {
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	v, err := g.read(ctx)
	fmt.Fprintf(w, "# HELP %s %s\n# TYPE %s gauge\n", g.name, g.help, g.name)
	if err == nil {
		fmt.Fprintf(w, "%s %s\n", g.name, formatFloat(v))
	}
}

func labelKey(names, values []string) string {
	if len(names) != len(values) {
		panic("metric label count mismatch")
	}
	if len(names) == 0 {
		return ""
	}
	parts := make([]string, len(names))
	for i, name := range names {
		parts[i] = name + "=" + strconv.Quote(values[i])
	}
	return "{" + strings.Join(parts, ",") + "}"
}

func withLabel(key, name, value string) string {
	label := name + "=" + strconv.Quote(value)
	if key == "" {
		return "{" + label + "}"
	}
	return key[:len(key)-1] + "," + label + "}"
}

func sortedKeys[V any](m map[string]V) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return keys
}

func formatFloat(v float64) string {
	if math.IsInf(v, 1) {
		return "+Inf"
	}
	return strconv.FormatFloat(v, 'g', -1, 64)
}
