// Command yas-report turns the raw output of scripts/test-all.sh into one
// self-contained HTML report: run summary, architecture, test tiers, the
// feature matrix with each feature's tests and their results, load figures,
// legacy defects, and open decisions.
//
// It writes the page twice: -out as a full HTML document for opening locally,
// and artifact.html beside it as a fragment for publishing.
package main

import (
	"bufio"
	"bytes"
	"encoding/json"
	"flag"
	"fmt"
	"html/template"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
)

type catalog struct {
	Features []struct {
		Area, Name, PHP, Go, Blocked string
		Tests                        []string
	} `json:"features"`
	Defects []struct {
		Severity, Title, Where, Go string
	} `json:"defects"`
	Decisions []string `json:"decisions"`
}

type result struct {
	Suite, Package, Test, Status string
	Seconds                      float64
	Output                       []string
}

type testRef struct {
	Ref, Short, Status string
}

type featureRow struct {
	Area, Name, PHP, Go, Blocked, Status string
	Tests                                []testRef
}

type tierRow struct {
	Key, Name, Proves, Status string
	Pass, Fail, Skip          int
	Seconds                   float64
	HasCounts                 bool
}

type page struct {
	Meta                                 map[string]string
	Tiers                                []tierRow
	Areas                                []string
	Features                             []featureRow
	FeaturePass, FeatureFail, FeatureGap int
	FeatureBlocked, FeatureTotal         int
	TestPass, TestFail, TestSkip         int
	Failures                             []result
	Load                                 map[string]string
	Defects                              []struct{ Severity, Title, Where, Go string }
	Decisions                            []string
	Overall                              string
}

var tierInfo = []struct{ key, name, proves string }{
	{"gofmt", "Formatting", "Every Go file is gofmt-clean."},
	{"vet", "Static analysis", "go vet over all packages, including the system suite."},
	{"unit", "Unit tests (race detector)", "Rules, parsing, ingress, metrics, redaction, and handlers, with -race."},
	{"stack", "Stack start", "Every service in the Compose stack reports ready."},
	{"integration", "Integration and crash recovery", "Workers, dispatchers, relay, and broker against real MySQL and RabbitMQ, including SIGKILL chaos."},
	{"system", "System journeys", "Black-box customer journeys over HTTP through every running service and the fake provider."},
	{"load", "Load", "Synthetic Soka traffic through the full stack, end to end."},
	{"report", "Report", "This page was generated."},
}

func main() {
	raw := flag.String("raw", "reports/raw", "directory with test-all.sh output")
	features := flag.String("features", "tests/features.json", "feature catalog")
	out := flag.String("out", "reports/index.html", "full HTML document to write")
	flag.Parse()

	var cat catalog
	if b, err := os.ReadFile(*features); err != nil || json.Unmarshal(b, &cat) != nil {
		fail("cannot read feature catalog")
	}
	results := map[string][]result{}
	var all []result
	for _, suite := range []string{"unit", "integration", "system"} {
		for _, r := range readGoTest(filepath.Join(*raw, suite+".json"), suite) {
			key := r.Package + "." + r.Test
			results[key] = append(results[key], r)
			all = append(all, r)
		}
	}
	p := page{Meta: readMeta(filepath.Join(*raw, "meta.json")), Load: readLoad(filepath.Join(*raw, "load.txt")), Decisions: cat.Decisions}
	for _, d := range cat.Defects {
		p.Defects = append(p.Defects, struct{ Severity, Title, Where, Go string }{d.Severity, d.Title, d.Where, d.Go})
	}
	tierStatus := readTiers(filepath.Join(*raw, "tiers.jsonl"))
	for _, t := range tierInfo {
		row := tierRow{Key: t.key, Name: t.name, Proves: t.proves, Status: tierStatus[t.key]}
		if row.Status == "" {
			row.Status = "not run"
		}
		for _, r := range all {
			if r.Suite == t.key {
				row.HasCounts = true
				row.Seconds += r.Seconds
				switch r.Status {
				case "pass":
					row.Pass++
				case "fail":
					row.Fail++
				default:
					row.Skip++
				}
			}
		}
		if t.key == "report" {
			row.Status = "pass"
		}
		p.Tiers = append(p.Tiers, row)
	}
	for _, r := range all {
		switch r.Status {
		case "pass":
			p.TestPass++
		case "fail":
			p.TestFail++
			p.Failures = append(p.Failures, r)
		default:
			p.TestSkip++
		}
	}
	seen := map[string]bool{}
	for _, f := range cat.Features {
		row := featureRow{Area: f.Area, Name: f.Name, PHP: f.PHP, Go: f.Go, Blocked: f.Blocked}
		if !seen[f.Area] {
			seen[f.Area] = true
			p.Areas = append(p.Areas, f.Area)
		}
		anyPass, anyFail := false, false
		for _, ref := range f.Tests {
			st := status(results[normalise(ref)])
			anyPass = anyPass || st == "pass"
			anyFail = anyFail || st == "fail" || st == "not run"
			parts := strings.Split(ref, ".")
			short := parts[len(parts)-1]
			pkg := strings.TrimPrefix(parts[0], "internal/")
			row.Tests = append(row.Tests, testRef{Ref: ref, Short: pkg + " · " + strings.TrimPrefix(short, "Test"), Status: st})
		}
		switch {
		case f.Blocked != "":
			row.Status = "blocked"
			p.FeatureBlocked++
		case anyFail:
			row.Status = "fail"
			p.FeatureFail++
		case anyPass:
			row.Status = "pass"
			p.FeaturePass++
		default:
			row.Status = "not run"
			p.FeatureGap++
		}
		p.Features = append(p.Features, row)
	}
	p.FeatureTotal = len(p.Features)
	p.Overall = "pass"
	for _, t := range p.Tiers {
		if t.Status == "fail" {
			p.Overall = "fail"
		}
	}
	if p.FeatureFail > 0 || p.TestFail > 0 {
		p.Overall = "fail"
	}

	var buf bytes.Buffer
	if err := tmpl.Execute(&buf, p); err != nil {
		fail(err.Error())
	}
	fragment := buf.Bytes()
	if err := os.MkdirAll(filepath.Dir(*out), 0o755); err != nil {
		fail(err.Error())
	}
	full := append([]byte("<!doctype html>\n<html lang=\"en\">\n<head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1, viewport-fit=cover\"></head>\n<body>\n"), fragment...)
	full = append(full, []byte("\n</body>\n</html>\n")...)
	if err := os.WriteFile(*out, full, 0o644); err != nil {
		fail(err.Error())
	}
	if err := os.WriteFile(filepath.Join(filepath.Dir(*out), "artifact.html"), fragment, 0o644); err != nil {
		fail(err.Error())
	}
	fmt.Printf("report: %d/%d features passing, %d blocked; %d tests passed, %d failed, %d skipped -> %s\n",
		p.FeaturePass, p.FeatureTotal, p.FeatureBlocked, p.TestPass, p.TestFail, p.TestSkip, *out)
}

// normalise turns "internal/workers/sokamo.TestX" into the go test -json key.
func normalise(ref string) string {
	i := strings.LastIndex(ref, ".")
	return "yas-modernization/" + ref[:i] + "." + ref[i+1:]
}

// status combines a test's results across tiers: an integration test that the
// unit tier skipped and the integration tier passed counts as passed.
func status(rs []result) string {
	st := "not run"
	for _, r := range rs {
		switch {
		case r.Status == "fail":
			return "fail"
		case r.Status == "pass":
			st = "pass"
		case st == "not run":
			st = "skip"
		}
	}
	return st
}

func readGoTest(path, suite string) []result {
	f, err := os.Open(path)
	if err != nil {
		return nil
	}
	defer f.Close()
	type event struct {
		Action, Package, Test, Output string
		Elapsed                       float64
	}
	outputs := map[string][]string{}
	failedTest := map[string]bool{}
	var out []result
	sc := bufio.NewScanner(f)
	sc.Buffer(make([]byte, 1<<20), 1<<24)
	for sc.Scan() {
		var e event
		if json.Unmarshal(sc.Bytes(), &e) != nil {
			continue
		}
		key := e.Package + "." + e.Test
		switch e.Action {
		case "output":
			if e.Test != "" {
				outputs[key] = append(outputs[key], strings.TrimRight(e.Output, "\n"))
			}
		case "pass", "fail", "skip":
			if e.Test == "" || strings.Contains(e.Test, "/") {
				// A package that fails without a failing test (build error,
				// timeout, panic in TestMain) still counts as a failure.
				if e.Test == "" && e.Action == "fail" && !failedTest[e.Package] {
					out = append(out, result{Suite: suite, Package: e.Package, Test: "(package)", Status: "fail"})
				}
				continue
			}
			r := result{Suite: suite, Package: e.Package, Test: e.Test, Status: e.Action, Seconds: e.Elapsed}
			if e.Action == "fail" {
				failedTest[e.Package] = true
				lines := outputs[key]
				if len(lines) > 25 {
					lines = lines[len(lines)-25:]
				}
				r.Output = lines
			}
			out = append(out, r)
		}
	}
	return out
}

func readTiers(path string) map[string]string {
	out := map[string]string{}
	b, err := os.ReadFile(path)
	if err != nil {
		return out
	}
	for _, line := range strings.Split(strings.TrimSpace(string(b)), "\n") {
		var t struct {
			Tier   string
			Status int
		}
		if json.Unmarshal([]byte(line), &t) == nil {
			out[t.Tier] = map[bool]string{true: "pass", false: "fail"}[t.Status == 0]
		}
	}
	return out
}

func readMeta(path string) map[string]string {
	out := map[string]string{}
	if b, err := os.ReadFile(path); err == nil {
		_ = json.Unmarshal(b, &out)
	}
	return out
}

var loadLines = map[string]*regexp.Regexp{
	"sent":     regexp.MustCompile(`sent (\d+) callbacks with (\d+) senders in ([\d.]+\w+): (\d+)/s`),
	"latency":  regexp.MustCompile(`p50=(\S+) p90=(\S+) p99=(\S+) max=(\S+)`),
	"endToEnd": regexp.MustCompile(`end to end: (\d+) processed by the MO worker in ([\d.]+\w+): (\d+)/s`),
	"accepted": regexp.MustCompile(`accepted (\d+), other (\d+)`),
}

func readLoad(path string) map[string]string {
	b, err := os.ReadFile(path)
	if err != nil {
		return nil
	}
	text := string(b)
	out := map[string]string{}
	if m := loadLines["sent"].FindStringSubmatch(text); m != nil {
		out["callbacks"], out["senders"], out["intakeTime"], out["intakeRate"] = m[1], m[2], m[3], m[4]
	}
	if m := loadLines["latency"].FindStringSubmatch(text); m != nil {
		out["p50"], out["p90"], out["p99"], out["max"] = m[1], m[2], m[3], m[4]
	}
	if m := loadLines["endToEnd"].FindStringSubmatch(text); m != nil {
		out["processed"], out["e2eTime"], out["e2eRate"] = m[1], m[2], m[3]
	}
	if m := loadLines["accepted"].FindStringSubmatch(text); m != nil {
		out["accepted"], out["rejected"] = m[1], m[2]
	}
	if len(out) == 0 {
		return nil
	}
	return out
}

func fail(msg string) {
	fmt.Fprintln(os.Stderr, "yas-report:", msg)
	os.Exit(2)
}

var funcs = template.FuncMap{
	"inArea": func(rows []featureRow, area string) []featureRow {
		var out []featureRow
		for _, r := range rows {
			if r.Area == area {
				out = append(out, r)
			}
		}
		return out
	},
	"secs": func(s float64) string {
		if s < 60 {
			return fmt.Sprintf("%.1f s", s)
		}
		return fmt.Sprintf("%.1f min", s/60)
	},
	"shortPkg": func(p string) string { return strings.TrimPrefix(p, "yas-modernization/") },
	"cls":      func(s string) string { return strings.ReplaceAll(s, " ", "-") },
	"sub":      func(a, b int) int { return a - b },
	"pct": func(a, b int) int {
		if b == 0 {
			return 0
		}
		return a * 100 / b
	},
	"sorted": func(m map[string]string) []string {
		keys := make([]string, 0, len(m))
		for k := range m {
			keys = append(keys, k)
		}
		sort.Strings(keys)
		return keys
	},
}

var tmpl = template.Must(template.New("report").Funcs(funcs).Parse(reportHTML))
