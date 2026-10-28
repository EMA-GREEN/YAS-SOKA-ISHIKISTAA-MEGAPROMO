# Migration completion checklist

The PHP repository is the behavioral reference. A checked item means the stated evidence exists in this Go project; it does **not** mean production is migrated. Work proceeds in order where one step supplies another. Production cutover remains the final gate.

## 1. Reference behavior and target design

- [x] Inventory the three PHP callback routes and source DDL families. Evidence: `docs/legacy-callback-contract.md`, `docs/source-schema-inventory.md`.
- [x] Define the target entities and visual ERD. Evidence: `docs/schema.md`, `migrations/001_callback_inbox_outbox.sql`, `migrations/002_core_domain.sql`.
- [ ] Trace every active PHP entry point, worker, queue, scheduled job, and external effect. Check: code-derived contract matrix with tests for each chosen behavior.
- [ ] Compare the code inventory with deployed jobs, live schema, and anonymized provider examples. Check: signed-off parity scope and verified column map.

## 2. Shared Go foundation

- [x] Receive core JSON callbacks with legacy response shape. Check: HTTP contract tests pass.
- [x] Store callbacks and outbox intent atomically; relay to new RabbitMQ queues. Check: disposable MySQL retry, conflict, and rollback tests pass.
- [x] Provide guarded, numbered target migrations. Check: disposable MySQL schema and smoke checks pass.
- [ ] Test broker confirms, returns, queue restart, dead letters, and consumer crash replay on an isolated RabbitMQ instance. Check: executable integration suite passes.
- [ ] Add authenticated ingress, operational metrics, structured log redaction, health checks, and deployable configuration. Check: security and operations tests pass.

## 3. Soka customer flows

- [x] Characterize callback payment routing and point-purchase rules from PHP. Check: branch tests pass.
- [x] Process a matched Soka point-purchase result into a payment event, one point award, and a customer-message intent. Check: disposable MySQL success, failure, replay, validation, and rollback tests pass.
- [x] Process an exact point-purchase MO for an active, verified subscriber into an interaction, pending payment, and charge intent. Check: disposable MySQL test proves replay creates one attempt, unknown keywords never charge, and the resulting callback awards points once.
- [ ] Send charge requests through a provider adapter and reconcile synchronous response with callbacks. Check: replay and timeout tests prove no duplicate charge.
- [ ] Deliver outbound SMS and record provider outcomes. Check: retries do not send unintended duplicates and delivery failures are visible.
- [ ] Migrate Soka gameplay, subscriptions, renewals, web/Android payments, low-balance fallbacks, and jobs. Check: PHP-derived scenario suite and reconciliation pass.

## 4. Ishikistaa and MegaPromo

- [ ] Implement Ishikistaa MO, payment, points, questions, activation, M-Push, renewals, and jobs. Check: product scenario suite, broker replay, and database reconciliation pass.
- [ ] Implement MegaPromo MO, billing, entries, prize positions, mobile-money push/collection, renewals, and jobs. Check: prize invariants and financial reconciliation pass.
- [ ] Cover remaining callback routes and formats, including M-Push and XML/JSON collection. Check: redacted provider fixtures and negative security cases pass.

## 5. Historical data and release

- [ ] Inspect live MySQL metadata read-only and verify all source-to-target field, ID, money, time, encoding, and status maps. Check: approved mapping report with no unresolved lossy fields.
- [ ] Rehearse backup restoration, bulk copy, incremental sync or write pause, and rollback. Check: counts, keys, balances, payment totals, and sample histories reconcile.
- [ ] Run unit, integration, race, load, security, and failure-recovery tests in production-like infrastructure. Check: recorded thresholds and acceptance results.
- [ ] Shadow the PHP system, compare results, then cut over an approved slice with monitoring and rollback. Check: owner acceptance and rollback window close before PHP retirement.

Current project status: **in progress, not production-ready**. No live database or provider traffic has been changed.
