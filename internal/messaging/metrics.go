package messaging

import (
	"time"

	"yas-modernization/internal/obs"
)

// ConsumerMetrics is shared by queue consumers. A nil value records nothing.
type ConsumerMetrics struct {
	Messages  *obs.CounterVec
	Duration  *obs.HistogramVec
	Connected *obs.GaugeVec
}

// Consumer outcomes. Review means the message went to the dead-letter queue;
// retry means it was left unacknowledged for redelivery.
const (
	OutcomeProcessed     = "processed"
	OutcomeNotApplicable = "not_applicable"
	OutcomeReview        = "review"
	OutcomeInvalidID     = "invalid_id"
	OutcomeRetry         = "retry"
)

func NewConsumerMetrics(reg *obs.Registry) *ConsumerMetrics {
	return &ConsumerMetrics{
		Messages: reg.Counter("yas_consumer_messages_total", "Queue messages handled by consumer and outcome.", "consumer", "outcome"),
		Duration: reg.Histogram("yas_consumer_process_seconds", "Message processing time by consumer.",
			[]float64{0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10}, "consumer"),
		Connected: reg.Gauge("yas_consumer_connected", "1 while the consumer holds a broker subscription.", "consumer"),
	}
}

func (m *ConsumerMetrics) Observe(consumer, outcome string, started time.Time) {
	if m == nil {
		return
	}
	m.Messages.Inc(consumer, outcome)
	if !started.IsZero() {
		m.Duration.Observe(time.Since(started).Seconds(), consumer)
	}
}

func (m *ConsumerMetrics) SetConnected(consumer string, up bool) {
	if m == nil {
		return
	}
	v := 0.0
	if up {
		v = 1
	}
	m.Connected.Set(v, consumer)
}
