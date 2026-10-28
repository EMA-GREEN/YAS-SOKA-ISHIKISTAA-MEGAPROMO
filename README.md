# YAS modernization — first implementation slice

This isolated Go workspace preserves the PHP source at `/Users/dany/Projects/work/YAS-SOKA-ISHIKISTAA-MEGAPROMO`. It is **not production-ready**. The existing application and databases have not been changed.

Implemented: JSON callback parsing for Soka, Ishikistaa, and MegaPromo; matching callback routes and success response shape; MySQL transaction for callback receipt and outbox intent; RabbitMQ relay with publisher confirms, retry, lease, and dead-letter state; health/readiness endpoints; unit and HTTP contract tests. The target domain schema now covers subscriptions, activation, questions, interactions, payments, answers, points, promotions, prizes, outbound messages, and processing deduplication. The relay publishes to a **new** queue and has no product consumer yet.

## Local commands

Use Go 1.24+ and dependencies from `go.mod`. `go run ./cmd/migrator` prints an offline migration plan without connecting to a database. For an empty, isolated MySQL 8.0+ development database, set `MIGRATION_DB_DSN` and run `go run ./cmd/migrator -apply -expected-db <exact_database_name>` after review. The command checks the target name, takes a database lock, records checksums and status, and refuses to continue after a partial failure. MySQL DDL cannot be rolled back as a transaction; inspect and repair a failed migration manually. Set `MYSQL_DSN` and start the API with `go run ./cmd/callbacks`. Set `AMQP_URL` and start `go run ./cmd/outbox-relay`. Never commit environment values.

`go test ./...` and `go test -race ./...` exercise the code without a database. The Go migration command applied both scripts and synthetic smoke data in a disposable MySQL 8.4 container; a second run skipped applied scripts, and a mismatched database name was rejected. Invalid cross-product references, missing parents, negative payment amounts, and duplicate point awards were rejected. MySQL/RabbitMQ application integration tests, provider contract fixtures, load tests, restore tests, and cutover rehearsal are pending.

## Deployment block

Do not switch provider traffic. The live schema and MySQL version are unknown; mobile money and M-Push routes, business workers, consumer use of the idempotency table, authentication controls, observability deployment, and rollback are incomplete. Review `docs/architecture.md` and `docs/schema.md` before continuing.
