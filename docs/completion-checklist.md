# Migration completion checklist

The PHP repository is the behavioral reference. A checked item means the stated evidence exists in this Go project; it does **not** mean production is migrated. Work proceeds in order where one step supplies another. Production cutover remains the final gate.

## 1. Reference behavior and target design

- [x] Inventory the three PHP callback routes and source DDL families. Evidence: `docs/legacy-callback-contract.md`, `docs/source-schema-inventory.md`.
- [x] Define the target entities and visual ERD. Evidence: `docs/schema.md`, `migrations/001_callback_inbox_outbox.sql`, `migrations/002_core_domain.sql`.
- [ ] Trace every active PHP entry point, worker, queue, scheduled job, and external effect. Check: code-derived contract matrix with tests for each chosen behavior. Partial evidence: `docs/legacy-process-matrix.md` covers every route and every kept-running process, with effects, defects, and decisions. Tests exist for the chosen behaviors already implemented; pending rows have none. Scheduled jobs cannot be traced without the deployed crontab.
- [ ] Compare the code inventory with deployed jobs, live schema, and anonymized provider examples. Check: signed-off parity scope and verified column map.

## 2. Shared Go foundation

- [x] Receive core JSON callbacks with legacy response shape. Check: HTTP contract tests pass.
- [x] Store callbacks and outbox intent atomically; relay to new RabbitMQ queues. Check: disposable MySQL retry, conflict, and rollback tests pass.
- [x] Provide guarded, numbered target migrations. Check: disposable MySQL schema and smoke checks pass.
- [x] Test broker confirms, mandatory returns, durable queue restart, dead letters, and redelivery after a consumer disconnect on isolated RabbitMQ. Check: executable broker suite passes before and after a container restart.
- [x] Test the full relay and product workers with MySQL and RabbitMQ through process crashes and recovery. Check: no lost callback, one financial effect per event, and visible dead letters. Evidence: `internal/relay/relay_integration_test.go` (injected worker failure, relay replay after confirm, dead letters) and `internal/e2e/crash_integration_test.go` (repeated SIGKILL of relay, MO, and point workers during MO-to-points traffic). Scope: the implemented Soka purchase path only; broker or database restarts mid-run and the charge/SMS dispatchers are not covered.
- [ ] Add authenticated ingress, operational metrics, structured log redaction, health checks, and deployable configuration. Check: security and operations tests pass. Partial evidence: `docs/operations.md` — fail-closed ingress policy (allowlist, trusted proxies, token, report mode, load shedding), redacting JSON logger in every process, metrics and health on a per-process loopback ops listener for the callback server, relay, consumers, and dispatchers, env example and systemd template, with unit, integration, and smoke tests. Remaining: provider-confirmed policy, TLS, log shipping and dashboards, host validation.

## 3. Soka customer flows

- [x] Characterize callback payment routing and point-purchase rules from PHP. Check: branch tests pass.
- [x] Process a matched Soka point-purchase result into a payment event, one point award, and a customer-message intent. Check: disposable MySQL success, failure, replay, validation, and rollback tests pass.
- [x] Process an exact point-purchase MO for an active, verified subscriber into an interaction, pending payment, and charge intent. Check: disposable MySQL test proves replay creates one attempt, unknown keywords never charge, and the resulting callback awards points once.
- [x] Build a guarded Soka charge dispatcher with stable request IDs and a no-auto-retry rule. Check: fake-provider/MySQL test proves one accepted request and no resend after an uncertain HTTP response.
- [ ] Verify the real charge-provider contract and reconcile synchronous responses, timeouts, and callbacks. Check: provider fixtures and failure tests prove no duplicate charge or lost outcome.
- [x] Build a guarded Soka purchase SMS dispatcher and record provider acceptance or uncertain results. Check: disposable MySQL and fake-provider test proves one accepted request, one event, and no resend after an uncertain HTTP response.
- [ ] Verify the real SMS-provider contract, delivery receipts, and manual reconciliation. Check: redacted fixtures and failure cases prove correct acceptance, visible delivery failures, and no unintended duplicates.
- [ ] Migrate Soka gameplay, subscriptions, renewals, web/Android payments, low-balance fallbacks, and jobs. Check: PHP-derived scenario suite and reconciliation pass. Partial evidence: paid answers with golden-hour and level scoring, the 99→45→30 low-balance ladder, next-question and reward messages, and keyword replies (`docs/soka-answer-flow.md`), covered by MySQL journey and crash tests. Subscription, reactivation, unsubscription, free and BOOM credits, and guarded renewal-system intents are also done (`docs/soka-subscription-flow.md`). Scheduled and on-demand open questions and the BOOM credit award are also done (`docs/soka-boom-and-open-questions.md`). Remaining: the BOOM usage charge, Predict-and-Win, web payments (blocked on the web app), Android payments, renewals, SMS reprocess, and scheduled jobs.

## 4. Ishikistaa and MegaPromo

- [ ] Implement Ishikistaa MO, payment, points, questions, activation, M-Push, renewals, and jobs. Check: product scenario suite, broker replay, and database reconciliation pass.
- [ ] Implement MegaPromo MO, billing, entries, prize positions, mobile-money push/collection, renewals, and jobs. Check: prize invariants and financial reconciliation pass.
- [ ] Cover remaining callback routes and formats, including M-Push and XML/JSON collection. Check: redacted provider fixtures and negative security cases pass. Partial evidence: Go intake for the three M-Push routes and the TigoPesa/Mixx JSON push routes, with legacy reply shapes, an explicit identity rule, and negative tests; smoke-tested through MySQL and RabbitMQ. The XML route is not ported pending owner confirmation, and no redacted provider samples exist yet. Workers for these events are pending.

## 5. Historical data and release

- [ ] Inspect live MySQL metadata read-only and verify all source-to-target field, ID, money, time, encoding, and status maps. Check: approved mapping report with no unresolved lossy fields.
- [ ] Rehearse backup restoration, bulk copy, incremental sync or write pause, and rollback. Check: counts, keys, balances, payment totals, and sample histories reconcile.
- [ ] Run unit, integration, race, load, security, and failure-recovery tests in production-like infrastructure. Check: recorded thresholds and acceptance results.
- [ ] Shadow the PHP system, compare results, then cut over an approved slice with monitoring and rollback. Check: owner acceptance and rollback window close before PHP retirement.

Current project status: **in progress, not production-ready**. No live database or provider traffic has been changed.
