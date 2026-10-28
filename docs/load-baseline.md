# Local load baseline

These numbers come from a developer laptop. They are for comparing changes, **not** production capacity: they don't meet the checklist's "recorded thresholds in production-like infrastructure".

## Method

`cmd/yas-loadgen` drives the load:
- **Safety:** it refuses a non-loopback target unless given `-allow-remote`, and refuses any database not named `yas_test_`.
- **Seeding:** with `-seed` it creates the Soka product, one question, and active synthetic subscribers (`2557999…`).
- **Traffic:** it posts Soka `InteractiveResponse` callbacks, 70% answers (each creates a 99 TZS charge intent) and 30% `help` replies.
- **Measurement:** it measures HTTP intake. With `-wait` it also waits until the MO worker has processed every accepted callback.

**Pipeline:** the real binaries were used: `callbacks` (ingress allowlist, `INGRESS_MAX_INFLIGHT=256`), `outbox-relay`, `soka-mo-worker` and `soka-point-worker`. They ran against MySQL 8.4 and RabbitMQ 4.1 in the isolated test containers, all inside one Docker VM on the laptop. No provider dispatchers were running, so no request left the machine.

```bash
LOADGEN_MYSQL_DSN=... yas-loadgen -seed -subscribers 200 -n 10000 -c 50 -wait 300s
```

## Results (2026-10-28)

All runs had 200 subscribers, and every callback was accepted (HTTP 200).

| Relay | MO workers | Callbacks / senders | Intake rate | Intake p50 / p99 | End-to-end rate |
|---|---|---|---|---|---|
| one event at a time | 1 | 2,000 / 20 | 1,745/s | 8 / 51 ms | **43/s** |
| one event at a time | 4 | 2,000 / 20 | 407/s | 39 / 217 ms | **95/s** |
| batches of 100 | 4 | 2,000 / 20 | 2,493/s | 6 / 37 ms | **533/s** |
| batches of 100 | 4 | 2,000 / 20 | 3,904/s | 4 / 20 ms | **415/s** |
| batches of 100 | 4 | 10,000 / 50 | 2,078/s | 17 / 163 ms | **579/s** |

**Checks on the 10,000-callback run:** 7,000 answers, 7,000 charge intents, every outbox row published, nothing dead-lettered.

**Run-to-run variation:** intake rates swing a lot because every process, MySQL and RabbitMQ share one VM's CPU. In the second row, intake fell while four workers competed for the same CPU.

## What changed as a result

1. **The relay was the bottleneck.** It claimed, published, awaited a confirm, and marked **one** event at a time. Four MO workers were idle about 80% of the time behind it.
   - **The fix:** the relay now claims up to `RELAY_BATCH_SIZE` events (default 100) under one lease, publishes them with pipelined publisher confirms, and marks the confirmed, routed ones in one update. A refused, returned or unconfirmed event is rescheduled on its own, as before. `internal/relay` has a test for a batch containing one unroutable event.
   - **Trade-off:** a relay killed mid-batch strands up to a batch of events until its 30-second lease expires. In the crash test, convergence sometimes took about 58 s instead of about 29 s. Delivery is not affected, and a smaller `RELAY_BATCH_SIZE` shortens the stall.
2. **MO processing scales by adding processes.** At about 7.5 ms per message each, one MO worker handles about 130 per second. PHP ran 15 MO consumers; run several `soka-mo-worker` processes, each with its own `OPS_ADDR`. They are safe to run in parallel because each message locks its subscription row.

## Mistake caught during measurement

Three early "batched" runs reported 373–554/s while every synthetic subscriber was **inactive**. Integration tests run between load runs had reset the database, and those runs did not re-seed. They measured the invitation path, not charged answers. The table above uses only seeded runs, each checked by counting answers and charges afterwards. Always pass `-seed` after a reset.

## Not covered

- **Production-like environment:** separate hosts, a production MySQL configuration, TLS and network latency.
- **Provider calls:** the charge and SMS dispatchers make provider calls and are rate-limited by the provider.
- **Sustained and mixed load:** soak tests, payment callbacks, and Ishikistaa traffic under load.
- **Failure under load:** broker or database failover during load.
