package main

const reportHTML = `<title>YAS Go Test Report</title>
<style>
:root {
  --bg: #f6f7f9; --surface: #ffffff; --surface-2: #f0f2f5; --text: #17202b; --muted: #5b6675;
  --line: #dde2e8; --accent: #2457c5; --pass: #18794e; --pass-bg: #e3f4ea; --fail: #c0332b; --fail-bg: #fbe6e4;
  --warn: #9a5b00; --warn-bg: #fbefd9; --idle: #5b6675; --idle-bg: #eceff3;
  --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --bg: #0f141a; --surface: #171e26; --surface-2: #1e2731; --text: #e6ebf1; --muted: #9aa6b4;
    --line: #2b3642; --accent: #7ea6ff; --pass: #4cc38a; --pass-bg: #123323; --fail: #ff7b72; --fail-bg: #3a1714;
    --warn: #f0b45a; --warn-bg: #3a2a10; --idle: #9aa6b4; --idle-bg: #232c36;
  }
}
:root[data-theme="dark"] {
  --bg: #0f141a; --surface: #171e26; --surface-2: #1e2731; --text: #e6ebf1; --muted: #9aa6b4;
  --line: #2b3642; --accent: #7ea6ff; --pass: #4cc38a; --pass-bg: #123323; --fail: #ff7b72; --fail-bg: #3a1714;
  --warn: #f0b45a; --warn-bg: #3a2a10; --idle: #9aa6b4; --idle-bg: #232c36;
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
main { max-width: 1120px; margin: 0 auto; padding: 32px 16px 64px; }
h1 { font-size: 28px; margin: 0 0 4px; letter-spacing: -0.01em; }
h2 { font-size: 19px; margin: 40px 0 12px; }
h3 { font-size: 15px; margin: 24px 0 8px; color: var(--muted); text-transform: uppercase; letter-spacing: .05em; }
p { margin: 0 0 12px; }
.muted { color: var(--muted); }
.mono { font-family: var(--mono); font-size: 13px; }
.card { background: var(--surface); border: 1px solid var(--line); border-radius: 10px; padding: 16px; }
.hero { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.verdict { font-weight: 650; padding: 6px 14px; border-radius: 999px; font-size: 14px; }
.verdict.pass { background: var(--pass-bg); color: var(--pass); }
.verdict.fail { background: var(--fail-bg); color: var(--fail); }
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; }
.tile .n { font-size: 30px; font-weight: 650; font-variant-numeric: tabular-nums; }
.tile .l { color: var(--muted); font-size: 13px; }
.bar { height: 6px; border-radius: 3px; background: var(--idle-bg); overflow: hidden; margin-top: 8px; display: flex; }
.bar span { display: block; height: 100%; }
.bar .p { background: var(--pass); } .bar .f { background: var(--fail); } .bar .b { background: var(--warn); }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 9px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
th { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; font-weight: 600; }
tr:last-child td { border-bottom: 0; }
.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.scroll { overflow-x: auto; }
.badge { display: inline-block; font-size: 12px; font-weight: 600; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
.badge.pass { background: var(--pass-bg); color: var(--pass); }
.badge.fail { background: var(--fail-bg); color: var(--fail); }
.badge.blocked { background: var(--warn-bg); color: var(--warn); }
.badge.skip, .badge.not-run { background: var(--idle-bg); color: var(--idle); }
.flow { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; align-items: stretch; }
.node { background: var(--surface-2); border: 1px solid var(--line); border-radius: 8px; padding: 10px 12px; font-size: 13px; }
.node b { display: block; font-size: 14px; margin-bottom: 2px; }
.step { color: var(--accent); font-weight: 700; font-size: 12px; }
.filters { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 12px; }
.filters button { font: inherit; font-size: 13px; padding: 5px 12px; border-radius: 999px; border: 1px solid var(--line); background: var(--surface); color: var(--text); cursor: pointer; }
.filters button[aria-pressed="true"] { background: var(--accent); border-color: var(--accent); color: var(--surface); }
.feature { border-top: 1px solid var(--line); padding: 12px 0; display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 4px 16px; }
.feature:first-of-type { border-top: 0; }
.feature .name { font-weight: 600; }
.feature .map { grid-column: 1 / -1; font-size: 13px; color: var(--muted); }
.feature .map span { color: var(--text); }
.chips { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
.chip { font-family: var(--mono); font-size: 12px; padding: 2px 8px; border-radius: 6px; border: 1px solid var(--line); background: var(--surface-2); }
.chip::before { content: ""; display: inline-block; width: 7px; height: 7px; border-radius: 50%; margin-right: 6px; background: var(--idle); vertical-align: 1px; }
.chip.pass::before { background: var(--pass); } .chip.fail::before { background: var(--fail); }
.blocked-note { grid-column: 1 / -1; font-size: 13px; color: var(--warn); }
pre { background: var(--surface-2); border: 1px solid var(--line); border-radius: 8px; padding: 12px; overflow-x: auto; font: 12px/1.45 var(--mono); margin: 8px 0 0; white-space: pre-wrap; word-break: break-word; }
.sev { font-weight: 650; font-size: 12px; text-transform: uppercase; }
.sev.critical, .sev.high { color: var(--fail); } .sev.medium { color: var(--warn); } .sev.low { color: var(--muted); }
ol.decisions { padding-left: 20px; margin: 0; } ol.decisions li { margin-bottom: 8px; }
.grid2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; }
@media (max-width: 640px) { h1 { font-size: 23px; } .tile .n { font-size: 24px; } th, td { padding: 8px 6px; } }
</style>
<main>
<div class="hero">
  <div>
    <h1>YAS Go rewrite: test report</h1>
    <div class="muted">Soka, Ishikistaa and MegaPromo callbacks, rewritten from PHP to Go.
    {{with .Meta.commit}}Commit <span class="mono">{{.}}</span>{{end}}{{with .Meta.finished}} · finished {{.}}{{end}}{{with .Meta.go}} · {{.}}{{end}}{{with .Meta.host}} · {{.}}{{end}}</div>
  </div>
  <div class="verdict {{.Overall}}">{{if eq .Overall "pass"}}All tiers passed{{else}}Failures found{{end}}</div>
</div>

<div class="tiles">
  <div class="card tile"><div class="n">{{.FeaturePass}} / {{sub .FeatureTotal .FeatureBlocked}}</div><div class="l">ported features with passing tests</div>
    <div class="bar"><span class="p" style="width:{{pct .FeaturePass .FeatureTotal}}%"></span><span class="f" style="width:{{pct .FeatureFail .FeatureTotal}}%"></span><span class="b" style="width:{{pct .FeatureBlocked .FeatureTotal}}%"></span></div></div>
  <div class="card tile"><div class="n">{{.TestPass}}</div><div class="l">test results passed · {{.TestFail}} failed · {{.TestSkip}} skipped</div></div>
  <div class="card tile"><div class="n">{{.FeatureBlocked}}</div><div class="l">features blocked on business decisions</div></div>
  {{with .Load}}<div class="card tile"><div class="n">{{.e2eRate}}/s</div><div class="l">end to end under load ({{.processed}} callbacks)</div></div>{{end}}
</div>

<h2>How the system runs</h2>
<div class="card">
  <p class="muted">One repository, separate processes that share MySQL and RabbitMQ. Every callback is written once to an inbox; every side effect goes through an outbox, so nothing is lost or sent twice when a process dies.</p>
  <div class="flow">
    <div class="node"><span class="step">1</span><b>callbacks</b>HTTP intake. Token or allowlist, inflight cap, dedup on request ID, stores to the inbox.</div>
    <div class="node"><span class="step">2</span><b>outbox-relay</b>Publishes stored events to RabbitMQ in batches with publisher confirms.</div>
    <div class="node"><span class="step">3</span><b>product workers</b>soka-mo, soka-points, open questions, ishikistaa-mo, ishikistaa-payment. Game rules and state.</div>
    <div class="node"><span class="step">4</span><b>dispatchers</b>charge, SMS, renewal, activation. Guarded, rate-limited, uncertain results go to review.</div>
    <div class="node"><span class="step">5</span><b>Tigo provider</b>A deterministic fake in tests; the real API only when explicitly enabled.</div>
  </div>
</div>

<h2>Test tiers</h2>
<div class="card scroll">
<table>
  <thead><tr><th>Tier</th><th>What it proves</th><th class="num">Passed</th><th class="num">Failed</th><th class="num">Skipped</th><th>Result</th></tr></thead>
  <tbody>
  {{range .Tiers}}<tr>
    <td><b>{{.Name}}</b></td><td class="muted">{{.Proves}}</td>
    {{if .HasCounts}}<td class="num">{{.Pass}}</td><td class="num">{{.Fail}}</td><td class="num">{{.Skip}}</td>{{else}}<td class="num muted">–</td><td class="num muted">–</td><td class="num muted">–</td>{{end}}
    <td><span class="badge {{cls .Status}}">{{.Status}}</span></td>
  </tr>{{end}}
  </tbody>
</table>
<p class="muted" style="margin:10px 0 0;font-size:13px">The unit tier skips tests that need MySQL or RabbitMQ; the integration tier runs them against real servers.</p>
</div>

{{if .Failures}}
<h2>Failures</h2>
{{range .Failures}}<div class="card" style="margin-bottom:10px">
  <b class="mono">{{shortPkg .Package}}.{{.Test}}</b> <span class="badge fail">{{.Suite}}</span>
  {{if .Output}}<pre>{{range .Output}}{{.}}
{{end}}</pre>{{end}}
</div>{{end}}
{{end}}

<h2>Features: PHP behaviour → Go → tests</h2>
<div class="filters" role="group" aria-label="Filter features">
  <button type="button" data-f="all" aria-pressed="true">All {{.FeatureTotal}}</button>
  <button type="button" data-f="pass" aria-pressed="false">Passing {{.FeaturePass}}</button>
  {{if .FeatureFail}}<button type="button" data-f="fail" aria-pressed="false">Failing {{.FeatureFail}}</button>{{end}}
  <button type="button" data-f="blocked" aria-pressed="false">Blocked {{.FeatureBlocked}}</button>
</div>
{{$rows := .Features}}
{{range .Areas}}<section class="area">
  <h3>{{.}}</h3>
  <div class="card">
  {{range inArea $rows .}}<div class="feature" data-status="{{.Status}}">
    <div class="name">{{.Name}}</div><div><span class="badge {{cls .Status}}">{{.Status}}</span></div>
    <div class="map">PHP: <span>{{.PHP}}</span>{{if .Go}} · Go: <span class="mono">{{.Go}}</span>{{end}}</div>
    {{if .Blocked}}<div class="blocked-note">Blocked: {{.Blocked}}</div>{{end}}
    {{if .Tests}}<div class="chips">{{range .Tests}}<span class="chip {{cls .Status}}" title="{{.Ref}}: {{.Status}}">{{.Short}}</span>{{end}}</div>{{end}}
  </div>{{end}}
  </div>
</section>{{end}}

{{with .Load}}
<h2>Load</h2>
<div class="card">
  <div class="grid2">
    <div><div class="muted">Intake</div><b>{{.callbacks}} callbacks</b> from {{.senders}} senders in {{.intakeTime}} ({{.intakeRate}}/s)<br>
      <span class="muted">accepted {{.accepted}}, rejected {{.rejected}}</span></div>
    <div><div class="muted">Intake latency</div><span class="mono">p50 {{.p50}} · p90 {{.p90}} · p99 {{.p99}} · max {{.max}}</span></div>
    <div><div class="muted">End to end</div><b>{{.processed}} processed</b> by the MO worker in {{.e2eTime}} ({{.e2eRate}}/s)</div>
  </div>
  <p class="muted" style="margin:12px 0 0;font-size:13px">Measured on one machine with every service, MySQL and RabbitMQ sharing its CPU. Use it to compare changes, not as production capacity.</p>
</div>
{{end}}

<h2>Legacy defects the rewrite fixes</h2>
<div class="card scroll">
<table>
  <thead><tr><th>Severity</th><th>Defect in PHP</th><th>Where</th><th>In Go</th></tr></thead>
  <tbody>{{range .Defects}}<tr><td><span class="sev {{.Severity}}">{{.Severity}}</span></td><td>{{.Title}}</td><td class="mono">{{.Where}}</td><td>{{.Go}}</td></tr>{{end}}</tbody>
</table>
</div>

<h2>Decisions needed before cutover</h2>
<div class="card"><ol class="decisions">{{range .Decisions}}<li>{{.}}</li>{{end}}</ol></div>

<h2>Re-running this report</h2>
<div class="card">
  <p>Everything runs locally against throwaway containers with random credentials and a fake provider; no request leaves the machine.</p>
  <pre>scripts/test-all.sh            # every tier, then reports/index.html
KEEP_STACK=1 scripts/test-all.sh   # leave the stack up for inspection
LOAD_N=0 scripts/test-all.sh       # skip the load tier</pre>
</div>
</main>
<script>
(function () {
  var buttons = document.querySelectorAll('.filters button');
  buttons.forEach(function (b) {
    b.addEventListener('click', function () {
      var f = b.getAttribute('data-f');
      buttons.forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      document.querySelectorAll('.area').forEach(function (area) {
        var shown = 0;
        area.querySelectorAll('.feature').forEach(function (row) {
          var on = f === 'all' || row.getAttribute('data-status') === f;
          row.style.display = on ? '' : 'none';
          if (on) shown++;
        });
        area.style.display = shown ? '' : 'none';
      });
    });
  });
})();
</script>
`
