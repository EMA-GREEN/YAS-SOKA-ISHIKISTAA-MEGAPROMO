# Migration plan and unresolved decisions

## Functional inventory from source code

| Domain | Existing entry points and effects | Go work remaining |
|---|---|---|
| Soka | MO and payment consumers, subscriptions, points, questions, open questions, prediction, web payments, SMS retry, renewals, credit awards | Capture fixtures for each branch; implement deterministic use cases and idempotent consumers |
| Ishikistaa | MO and payment consumers, questions, points, open questions, subscriptions, M-Push, renewals | Same parity and recovery work |
| MegaPromo | MO/gameplay, payment, TigoPesa push/collection, prize positions, entries, renewals, M-Push | Verify winning/entry rules and payment reconciliation before coding |
| Shared callback gateway | Three JSON notifications plus M-Push, XML collection and JSON push routes | Only core JSON receipt is implemented; all others pending |
| Reporting and operations | Hourly/daily reports, email scripts, scheduled question and renewal jobs, process check loops | Inventory deployed schedules and outputs; move only after owner sign-off |

Repository filenames are evidence of possible functionality, not proof every script runs in production. The deployed process list and traffic samples must decide parity scope.

## Phase gates

1. **Discovery:** read-only MySQL metadata and sampled anonymized records; `SHOW CREATE TABLE` for every dated table family, views, triggers, procedures, indexes, row counts, growth, duplicates, orphans, nulls, slow queries, transactions, backup and restore results. Record live routes, queue topology, schedules, host topology, provider contracts, security controls, and retention obligations.
2. **Behavior baseline:** recorded redacted requests and expected responses; replay tests around payment success/failure, retries, duplicate callback, midnight table rollover, MO and SMS, subscription and prize outcomes. Verify PHP behavior and document deviations to be fixed.
3. **Architecture approval:** approve source-to-target column maps, live DB compatibility, identity rules, domain boundaries, telemetry, deployment, and recovery. The target DDL and migrator are implemented for isolated testing, not approved for production.
4. **Implementation:** migrate one product path at a time. Add an idempotent consumer ledger keyed by outbox event ID before consuming new queue messages. Implement billing/SMS adapters with context deadlines and TLS verification. Keep PHP active as behavioral reference.
5. **Data movement (only if needed):** verify backups and restore, bulk copy with checkpoints, CDC/incremental sync or controlled write pause, reconcile keys/counts/totals/checksums/historical samples, rehearse rollback including writes made after cutover.
6. **Cutover:** shadow traffic and compare outputs; switch a small approved slice, observe, then expand. Do not decommission PHP or old tables until acceptance criteria and rollback window close.

## Specific design decisions pending evidence

- **Callback identity:** current draft uses `(product, external_request_id, event_kind)` and rejects a different payload for the same identity. Determine whether the provider legitimately reuses request IDs or changes non-business fields on retry. Then update the key and tests before live use.
- **Amount representation:** the target draft uses integer minor units, but callback `ChargeAmount` semantics and currency must be verified against production examples.
- **Date-named tables:** preserve source data and identifiers; compare native partitioning, archival, and compatibility views only after cataloguing all table families and query patterns.
- **Authentication:** the inspected controllers show no application-level signature check. Confirm whether ingress is protected by gateway, network, or provider signatures before selecting mTLS, HMAC, or another mechanism. Do not silently reject legitimate callbacks.
- **Outbox:** broker confirmation plus database mark provides at-least-once delivery. A crash between them can duplicate an event; consumers must commit an idempotency record with their business transaction. The relay does not deliver to PHP queues.
- **Deployment:** choose process supervision based on actual hosts. Do not assume Kubernetes. Define health/readiness, secret injection, pooled DB connections, safe rollout, and rollback before production.

## Observability blueprint

Use `slog` JSON without payload, MSISDN, or secret values. Propagate request ID and outbox event ID; add OpenTelemetry context when workers exist. Expose Prometheus metrics for callback count/latency by product and outcome, DB latency/pool pressure, outbox age/backlog/dead letters, broker publish retries, consumer lag, charge outcome, and SMS outcome. Collect logs with Grafana Alloy into Loki; dashboard and alert in Grafana. Add Tempo only if trace volume and troubleshooting need justify it. Set metric/log retention from operational and privacy requirements. This design is not yet deployed.

## Validation before production

Unit and HTTP tests pass for the first slice. Callback MySQL integration tests with injected outbox failure now pass in a disposable database. The Soka point-purchase worker now has a disposable-MySQL integration test for success, failures, replay, invalid amounts, and rollback. Still required: remaining worker integration tests, RabbitMQ publisher confirmation and return tests, broker consumer crash/replay tests, full provider contract tests, race/load tests, TLS and authorization tests, backup restoration, migration rehearsal, and production-like benchmark. No performance improvement claim is made.
