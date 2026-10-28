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
- **Metrics:** the callback server serves Prometheus text format on `OPS_ADDR` (default `127.0.0.1:9091`), never on the public listener, along with `/healthz` and `/readyz`.
  - `yas_callback_requests_total{product,outcome}` with outcomes `accepted`, `invalid`, `too_large`, `conflict` and `unavailable`
  - `yas_callback_duration_seconds{product}`
  - `yas_ingress_denied_total{reason,mode}` with reasons `source_not_allowed`, `bad_token` and `bad_client_address`
  - `yas_ingress_overloaded_total`
  - `yas_outbox_backlog` and `yas_outbox_dead_letters`, read at scrape time and omitted if the database cannot be read, so alerts see missing data rather than a false zero
- Label values come only from fixed sets in code, never from request input.

Suggested first alerts: outbox backlog growing for 5 minutes; any outbox dead letter; `unavailable` outcomes above zero; any enforced ingress denial, which is either an attack or a provider network change; missing scrape.

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

Still open:
- relay and worker metrics endpoints, including consumer lag and charge/SMS outcomes;
- the provider-confirmed ingress policy;
- TLS termination;
- log shipping and dashboards;
- validation of the units on the real hosts.
