// Package obs holds the shared logging and metrics used by every process.
package obs

import (
	"io"
	"log/slog"
	"regexp"
	"strings"
)

const redacted = "[redacted]"

// Attribute keys whose values are never written, whatever they contain.
var secretKeys = map[string]bool{
	"msisdn": true, "payload": true, "body": true, "token": true, "password": true,
	"secret": true, "authorization": true, "dsn": true, "amqp_url": true, "api_key": true,
}

var (
	// Tanzanian MSISDNs and any other long digit run that may identify a customer.
	longDigits = regexp.MustCompile(`\d{9,}`)
	// Credentials embedded in DSNs or URLs, such as user:secret@host.
	userInfo = regexp.MustCompile(`([A-Za-z][A-Za-z0-9+.-]*://)?[^\s:/@]+:[^\s@]+@`)
)

// Redact masks customer identifiers and embedded credentials in free text,
// including error messages that may quote driver or provider output.
func Redact(s string) string {
	s = userInfo.ReplaceAllString(s, "${1}"+redacted+"@")
	return longDigits.ReplaceAllString(s, redacted)
}

// NewLogger writes JSON logs with secret keys dropped and string values redacted.
func NewLogger(w io.Writer) *slog.Logger {
	return slog.New(slog.NewJSONHandler(w, &slog.HandlerOptions{ReplaceAttr: replace}))
}

func replace(_ []string, a slog.Attr) slog.Attr {
	if secretKeys[strings.ToLower(a.Key)] {
		return slog.String(a.Key, redacted)
	}
	switch a.Value.Kind() {
	case slog.KindString:
		return slog.String(a.Key, Redact(a.Value.String()))
	case slog.KindAny:
		if err, ok := a.Value.Any().(error); ok {
			return slog.String(a.Key, Redact(err.Error()))
		}
		if s, ok := a.Value.Any().(interface{ String() string }); ok {
			return slog.String(a.Key, Redact(s.String()))
		}
	}
	return a
}
