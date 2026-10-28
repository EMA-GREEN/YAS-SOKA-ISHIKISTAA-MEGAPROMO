# Project state

Updated: 2026-10-28 (Africa/Dar_es_Salaam)

## Locations

- New Go implementation: `/Users/dany/Projects/work/YAS-SOKA-ISHIKISTAA-MEGAPROMO-GO`
- Existing PHP reference, unchanged: `/Users/dany/Projects/work/YAS-SOKA-ISHIKISTAA-MEGAPROMO`

## Confirmed context

The source application is PHP, not Python. Its callback gateway uses CodeIgniter 3.1.4; product workers cover Soka, Ishikistaa, and MegaPromo. MySQL and RabbitMQ are configured. The live database schema, version, row volumes, deployed job list, and provider contract samples remain unavailable.

## Implemented in the new project

- Go callback intake for the three core JSON notification routes, with legacy success response shape.
- Transactional MySQL callback inbox/outbox and separate RabbitMQ relay.
- Target schema in numbered SQL files for subscriptions, activation, questions, interactions, payments, answers, points, promotions, prizes, outbound messages, and processing deduplication.
- Offline-plan-by-default Go migrator with explicit apply flag, target database name check, checksums, status tracking, and a migration lock.
- Editable Mermaid target ERDs and migration roadmap in `docs/`.
- Code-based source table-family inventory and preliminary source-to-target mapping in `docs/source-schema-inventory.md`.
- PHP-derived callback contract and payment routing characterization in `docs/legacy-callback-contract.md`, with Go tests for all three product routes and ordered payment branches.
- Soka MO and point-purchase result workers with dedicated durable queues. Exact purchase MOs create pending payments and charge intents; matched successful callbacks create one point award and customer-message intent. Details and limits are in `docs/soka-point-flow.md`.
- Completion checklist with evidence-based checked steps in `docs/completion-checklist.md`.
- Guarded Soka charge dispatcher that builds the legacy-shaped HTTP request and moves uncertain results to manual review without automatic resend. It has only contacted a fake local provider; see `docs/charge-dispatch.md`.
- Guarded Soka purchase SMS dispatcher that creates the legacy-shaped InteractiveRequest, records provider acceptance or review state, and never resends uncertain requests automatically. It has only contacted a fake local provider; see `docs/sms-dispatch.md`.

- Fail-closed callback ingress policy (source allowlist with trusted-proxy handling, optional shared token, report mode, in-flight limit), redacting JSON logger for every process, Prometheus metrics and health on a per-process loopback ops listener (callbacks, relay, consumers, dispatchers), and an env example plus hardened systemd template. See `docs/operations.md`.

- Legacy process and effect matrix (`docs/legacy-process-matrix.md`), covering routes, kept-running processes, external effects, legacy defects (string-built SQL, lossy unconfirmed publishing, disabled TLS checks, public test routes), and Go decisions.
- Go intake for M-Push subscription-init and MegaPromo TigoPesa/Mixx push callbacks, acknowledged only after durable storage.

- Soka paid-answer gameplay across the MO and payment workers, with migration `005_soka_answers.sql`: current question, answer level, answer scoring, fallback attempts, and dated level bonuses.
- Broker topology: the catch-all `yas.callback.events` queue (bound to `#`, no consumer) is removed. Received kinds without a worker are parked in `yas.{soka,ishikistaa,megapromo}.pending`, and any other kind is unroutable, so it becomes a visible outbox dead letter. Integration tests share `internal/testdb.Reset`, so they can run in sequence against one database.

- Soka subscription lifecycle (activation, reactivation, unsubscription, inactive and unknown subscribers), free and BOOM credit answers, and guarded renewal-system intents with `cmd/soka-renewal-dispatcher`. Migration `006_soka_subscriptions.sql`.

- Soka BOOM credit award rounds (`cmd/soka-boom-credit-award`, migration 007) and open questions, both scheduled (`cmd/soka-open-question-scheduler`, migration 008) and on the provider's no-open-question reason.

- Soka M-Push subscription-init activations: intents in the MO worker, confirmation on Activation, and the guarded `cmd/soka-activation-dispatcher` (migration 009).

- Soka Predict-and-Win: fixture list, slot choice, score submission with a 99 TZS charge, and payment confirmation or reopening (migration 010). Grading predictions as won or lost is not in the source repository.

## Validation completed

- `go test -race ./...` and `go vet ./...` passed.
- The numbered SQL migrations and synthetic smoke data applied in a disposable, network-isolated MySQL 8.4 container: 21 application tables, 38 foreign keys, plus the migrator tracking table.
- Migrator rerun skipped applied scripts; mismatched target name was rejected.
- Invalid cross-product links, missing parents, negative payment amounts, and duplicate point awards were rejected.
- The callback MySQL integration test passed in a separate disposable, network-isolated MySQL 8.4 container: retry deduplication, changed-payload conflict, and rollback after an injected outbox failure.
- The three PHP callback controllers pass PHP syntax checks. Go tests and vet pass after the PHP-derived contract additions.
- The Soka point-purchase integration test passed against a disposable MySQL 8.4 database for success, replay, low balance, generic failure, non-purchase skip, unknown tier, missing billing ID, amount mismatch, and injected rollback.
- The Soka MO integration test passed in disposable MySQL 8.4, including a combined MO-to-payment-to-points check, replay, menu for unknown purchase keywords, unverified billing segment rejection, BOOM offer selection, and injected rollback.
- The charge dispatcher passed an isolated MySQL plus fake-provider test for request shape, acceptance, uncertain HTTP response, and no automatic resend.
- The SMS dispatcher passed an isolated MySQL plus fake-provider test for request shape, one acceptance event, uncertain HTTP response, and no automatic resend. Provider acceptance does not prove handset delivery.
- An isolated RabbitMQ 4.1 test passed for publisher confirmation, mandatory return, Soka routing, dead-letter handling, redelivery after channel closure, and durable message recovery across broker restart. Full worker plus database crash replay remains open.
- An end-to-end relay-to-point-worker test passed against isolated MySQL 8.4 and RabbitMQ 4.1: a callback saved to the outbox was relayed and consumed; an injected worker transaction failure left no award and the message was redelivered until it succeeded once; reopening the outbox as if the relay crashed after broker confirmation republished the event without a second financial effect; a malformed message reached the visible dead-letter queue. 
- A crash-recovery test (`internal/e2e`) runs the relay, Soka MO worker, and Soka point worker as separate processes against isolated MySQL 8.4 and RabbitMQ 4.1. While 40 customers send purchase MOs and a simulated provider answers each charge (sometimes twice), it SIGKILLs and restarts a random process every 150–650 ms for 25 seconds and occasionally reopens published outbox rows. Four runs, each with 40–48 kills and 9–17 forced replays, converged to exactly one interaction, payment attempt, charge intent, payment event, point award with the correct amount, and customer message per purchase, with empty work and dead-letter queues and a clean SIGTERM shutdown. A deliberately broken worker that acknowledged before committing failed the test with a lost award. Broker or MySQL restarts during the run, the charge and SMS dispatchers, and non-purchase paths remain outside this test.
- Ingress, redaction, and metrics unit tests pass under `-race`. A smoke run of the built callback server in the isolated network refused to start without a policy, rejected a missing token with 403, accepted a tokened callback with the legacy response, exposed metrics only on loopback, wrote no MSISDN to logs, and shut down cleanly. The relay integration test asserts relay and consumer metrics, and a smoke run of the built relay, workers, and charge dispatcher served ready checks and metrics on their own ports.
- The crash-recovery test now kills its child processes on any failure. An earlier failing run had left two orphaned workers consuming from the shared test queues, which broke a later relay test until they were removed. Its detection of an acknowledge-before-commit bug is probabilistic: that bug was caught in 2 of 3 runs, because it only shows when a kill lands inside the 200 ms window.
- Disposable containers were removed. No production database was touched and no remote was pushed.

## Current boundary and next work

This is a target implementation, not a verified production data migration. Do not switch production traffic. Obtain read-only schema metadata and table statistics, deployed process and queue configuration, and redacted provider callback samples. Then validate the preliminary source-to-target map against live metadata, extend code-derived contracts through the remaining payment workers and MO paths, verify real charge and SMS-provider reconciliation, delivery receipts, and implement other product workers, observability deployment, data reconciliation, backup/restore rehearsal, and cutover plan before production use.

Keep the PHP repository intact as the behavioral reference. Do not commit credentials, dumps, or customer data. The new project is an independent local Git repository on `main`, with no configured remote.
