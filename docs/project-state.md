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

## Validation completed

- `go test -race ./...` and `go vet ./...` passed.
- Both SQL migrations and synthetic smoke data applied in a disposable, network-isolated MySQL 8.4 container: 20 application tables, 36 foreign keys, plus the migrator tracking table.
- Migrator rerun skipped applied scripts; mismatched target name was rejected.
- Invalid cross-product links, missing parents, negative payment amounts, and duplicate point awards were rejected.
- The callback MySQL integration test passed in a separate disposable, network-isolated MySQL 8.4 container: retry deduplication, changed-payload conflict, and rollback after an injected outbox failure.
- The three PHP callback controllers pass PHP syntax checks. Go tests and vet pass after the PHP-derived contract additions.
- Disposable containers were removed. No production database was touched and no remote was pushed.

## Current boundary and next work

This is a target implementation, not a verified production data migration. Do not switch production traffic. Obtain read-only schema metadata and table statistics, deployed process and queue configuration, and redacted provider callback samples. Then validate the preliminary source-to-target map against live metadata, extend code-derived contracts through payment workers, implement idempotent product workers, observability deployment, data reconciliation, backup/restore rehearsal, and cutover plan before production use.

Keep the PHP repository intact as the behavioral reference. Do not commit credentials, dumps, or customer data. The new project is an independent local Git repository on `main`, with no configured remote.
