# Ingress, telemetry, and deployment

The PHP callback gateway had no application-level authentication: any client that could reach it could post callbacks. The Go callback server refuses to start without an explicit ingress policy.

## Ingress policy (`internal/ingress`)

| Variable | Meaning |
|---|---|
| `INGRESS_AUTH_MODE` | `enforce` (default) rejects with 403; `report` admits but logs and counts would-be rejections; `off` admits everything and must be set explicitly. |
| `INGRESS_ALLOWED_CIDRS` | Comma-separated provider networks or addresses. When set, the client address must match. |
| `INGRESS_TRUSTED_PROXY_CIDRS` | Proxies whose `X-Forwarded-For` is honored. The header is read right to left, skipping trusted hops, so a client cannot forge an allowed address by prepending one. Peers outside this list are judged by their socket address. |
| `INGRESS_TOKEN` | Optional shared secret of at least 32 characters, sent as `X-Callback-Token` or `Authorization: Bearer`. Compared by SHA-256 digest in constant time and never logged. |
| `INGRESS_MAX_INFLIGHT` | Concurrent callback requests (default 64). Excess requests get 503 with `Retry-After`, which providers treat as retryable, protecting MySQL during floods. |

With both an allowlist and a token, both must pass. `enforce` and `report` need at least one. Health routes are never guarded. Bodies over 1 MiB and headers over 16 KiB are refused.

**Rollout:** the provider's source networks and whether it can send a header are not yet confirmed. Start in `report` mode with the believed networks. Watch `yas_ingress_denied_total{mode="report"}` and the `callback failed ingress policy` log lines against real traffic, then switch to `enforce`. Do not enforce on guesses: rejected legitimate callbacks are lost billing results unless the provider retries them.

## Telemetry

- **Logs:** every process writes JSON through `obs.NewLogger`. It drops values under keys such as `msisdn`, `payload`, `token`, `password` and `dsn`. In all other strings and error messages it masks runs of 9 or more digits (MSISDNs, which also hides long timestamps) and `user:secret@` credentials. Numeric attributes such as event IDs are kept.
- **Metrics:** every process serves Prometheus text format with `/healthz` and `/readyz` (database ping) on its own loopback port. The ports are callbacks 9091, outbox-relay 9092, soka-mo-worker 9093, soka-point-worker 9094, soka-charge-dispatcher 9095, soka-sms-dispatcher 9096, soka-renewal-dispatcher 9097, soka-open-question-scheduler 9098, soka-activation-dispatcher 9099, ishikistaa-mo-worker 9100 and ishikistaa-payment-worker 9101; `OPS_ADDR` overrides the port. The callback server never serves metrics on its public listener. A process whose ops port is taken refuses to start.
  - `yas_callback_requests_total{product,outcome}` with outcomes `accepted`, `invalid`, `too_large`, `conflict` and `unavailable`
  - `yas_callback_duration_seconds{product}`
  - `yas_ingress_denied_total{reason,mode}` with reasons `source_not_allowed`, `bad_token` and `bad_client_address`
  - `yas_ingress_overloaded_total`
  - relay: `yas_relay_published_total{product,kind}`, `yas_relay_publish_failures_total{product,kind}`, `yas_relay_connected`, and the database-read gauges `yas_outbox_backlog`, `yas_outbox_dead_letters` and `yas_outbox_oldest_pending_seconds`
  - consumers: `yas_consumer_messages_total{consumer,outcome}` with outcomes `processed`, `not_applicable`, `review` (dead-lettered), `invalid_id` and `retry` (left for redelivery); `yas_consumer_process_seconds{consumer}`; `yas_consumer_connected{consumer}`
  - dispatchers: `yas_charge_intents{state}`, `yas_outbound_messages{state}`, `yas_renewal_requests{state}` and `yas_activation_attempts{state}` for the open and review states only (terminal states grow without bound), and `yas_dispatch_attempts_total{dispatcher,result}` with results `ok`, `uncertain` (claimed, needs manual review) and `failed_before_claim`
- Gauges read from the database at scrape time are omitted when the read fails, so alerts see missing data rather than a false zero.
- Label values come only from fixed sets in code, never from request input. The relay's `kind` label is limited to the event types seen in the PHP controllers, and anything else is counted as `other`.
- Queue depth and consumer counts come from RabbitMQ's own `rabbitmq_prometheus` plugin rather than these processes. Watch the `yas.*.pending` holding queues: they keep callback kinds whose workers do not exist yet and have no consumer.

Suggested first alerts:
- oldest pending outbox row older than 2 minutes;
- any outbox dead letter;
- `unavailable` callback outcomes above zero;
- any enforced ingress denial, which is either an attack or a provider network change;
- a consumer or the relay reporting disconnected for more than a minute;
- consumer `review` or `retry` outcomes rising;
- any `NEEDS_REVIEW` charge or message, or any `uncertain` dispatch;
- a missing scrape.

## Tigo API token

The charge, SMS and activation dispatchers need a bearer token. PHP's `UserAccessToken.php` logs in to `/api/auth/login`, assumes the token lasts 10 days, and writes it into every product database for the workers to read.

In Go each dispatcher gets its token itself (`internal/tigoauth`):
- **Login mode:** with `TIGO_LOGIN_URL` (HTTPS), `TIGO_LOGIN_USERNAME` and `TIGO_LOGIN_PASSWORD` set, it logs in and keeps the token in memory. It logs in again after `TIGO_TOKEN_TTL` (default `24h`, at most `240h`).
- **Fixed-token mode:** without `TIGO_LOGIN_URL`, it uses the fixed `CHARGE_BEARER_TOKEN`, `SMS_BEARER_TOKEN` or `ACTIVATION_ACCESS_TOKEN`.

The token is fetched before any work is claimed, so a failed login never leaves a charge, SMS or activation half-sent. The real token lifetime and whether the endpoint rejects early re-login are not confirmed.

## Scaling

`docs/load-baseline.md` covers this:
- **MO and payment workers:** scale them by running more processes, as PHP did. Each needs its own `OPS_ADDR`.
- **Relay:** publishes in batches of `RELAY_BATCH_SIZE`. Several relays can run at once because claims use `SKIP LOCKED`.

## Deployment

`deploy/yas.env.example` lists every variable with placeholders. `deploy/systemd/yas@.service` is a hardened template unit for each binary, using `yas@callbacks`, `yas@outbox-relay` and so on. It restarts on failure and stops with SIGTERM, which the crash-recovery test shows is safe at any point. The real host topology is still unknown, so these files are a starting point. They have not been validated on a target host.

## Evidence and gaps

Unit tests cover:
- config failing closed;
- allowlist, IPv4-mapped IPv6 and IPv6 matching;
- forged `X-Forwarded-For`;
- token header and bearer;
- both factors required;
- report mode;
- load shedding;
- guard coverage of every callback route but not health routes;
- outcome metrics;
- log redaction;
- metric exposition.

A smoke run of the built server in the isolated network:
- refused to start without a policy;
- returned 403 without the token and 200 with the legacy response shape when it was sent;
- served metrics only on the loopback ops port;
- wrote no MSISDN to its logs;
- shut down cleanly on SIGTERM.

For relay and worker metrics:
- the relay integration test asserts relay publishes, consumer retry, invalid-ID and processed outcomes, and the connected gauge going to 0 on stop;
- a smoke run of the built relay, MO worker, point worker and charge dispatcher served ready checks and metrics on their own ports;
- a second relay refused to start on a taken port;
- no credentials appeared in their logs.

Still open:
- the provider-confirmed ingress policy;
- TLS termination;
- log shipping and dashboards;
- validation of the units on the real hosts.
