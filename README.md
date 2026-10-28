# YAS modernization — first implementation slice

This isolated Go workspace preserves the PHP source at `/Users/dany/Projects/work/YAS-SOKA-ISHIKISTAA-MEGAPROMO`. It is **not production-ready**. The existing application and databases have not been changed.

Implemented: JSON callback parsing for Soka, Ishikistaa, and MegaPromo; matching callback routes and success response shape; MySQL transaction for callback receipt and outbox intent; RabbitMQ relay with publisher confirms, retry, lease, and dead-letter state; health/readiness endpoints; unit and HTTP contract tests. The relay publishes to a **new** queue and has no product consumer yet.

## Local commands

Use Go 1.24+ and dependencies from `go.mod`. Set `MYSQL_DSN` via the environment. Apply `migrations/001_callback_inbox_outbox.sql` only to an isolated MySQL 8.0+ development database after review. Start the API with `go run ./cmd/callbacks`. Set `AMQP_URL` and start `go run ./cmd/outbox-relay`. Never commit environment values.

`go test ./...` and `go test -race ./...` exercise the code without a database. MySQL/RabbitMQ integration tests, provider contract fixtures, load tests, restore tests, and cutover rehearsal are pending.

## Deployment block

Do not switch provider traffic. The live schema and MySQL version are unknown; mobile money and M-Push routes, business workers, idempotent consumer ledger, authentication controls, observability deployment, and rollback are incomplete. Review `docs/architecture.md` and `docs/schema.md` before continuing.
