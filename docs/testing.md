# Testing

`scripts/test-all.sh` runs every tier and writes `reports/index.html`. The report maps each PHP behaviour to its Go code and the tests that cover it, and shows tier results, load figures, the legacy defects the rewrite fixes, and the decisions still open.

```bash
scripts/test-all.sh                # every tier, then the report; stack torn down
KEEP_STACK=1 scripts/test-all.sh   # leave the stack running for inspection
LOAD_N=0 scripts/test-all.sh       # skip the load tier
```

**Needs:** Go 1.24, Docker with Compose, and free loopback ports 13306, 15673, 18080, 18090, and 19091–19101.

**Safety:** the run uses throwaway containers and fresh random credentials, and nothing is committed. Dispatchers call the fake provider on loopback, so no request leaves the machine. Raw output under `reports/raw` has its credentials redacted, and `reports/` is ignored by git.

## Tiers

| Tier | What runs | Where |
|---|---|---|
| Static | `gofmt`, `go vet` (including the `system` build tag) | host |
| Unit | `go test -race ./...`. Tests needing MySQL or RabbitMQ skip here. | host |
| Stack | builds Linux binaries and starts MySQL, two RabbitMQ brokers, and the app container with 11 services and the fake provider; waits for every `/readyz` | `deploy/compose` |
| Integration | `Integration` and `Broker` tests against real MySQL (`yas_test_integration`) and an isolated broker, including the SIGKILL crash test | host → containers |
| System | `tests/system`: black-box journeys over HTTP through every running service | host → stack |
| Load | `cmd/yas-loadgen` (5,000 Soka callbacks by default) through the full stack | host → stack |
| Report | `cmd/yas-report` | host |

A failing tier is recorded and the run carries on, so the report shows the whole picture. The script exits non-zero if any tier failed.

## The fake provider

`cmd/yas-fakeprovider` stands in for Tigo's login, charge, SMS, activation and renewal APIs, and records every request (`GET /requests`). After a charge it posts the payment callback back to the product's route, as Tigo does. The last digit of the synthetic MSISDN chooses the outcome:

| Last digit | Outcome |
|---|---|
| 1 | low balance at 99 TZS, then success at 45 |
| 2 | low balance at every tier |
| 3 | billing error |
| 4 | first SMS refused, the resend accepted |
| other | success |

System tests use MSISDNs starting `2557999`; the load generator uses `2557998`.

## The feature catalog

`tests/features.json` lists every feature with its PHP source, Go location, and test names (`package.TestName`). The report marks a feature:
- **pass** when every listed test passed in some tier;
- **fail** when any failed or never ran;
- **blocked** when it waits on an owner decision.

Add new tests to the catalog so they appear in the report.

## Running against staging

A local run proves behaviour, not capacity. Before cutover, run the system and load tiers against a staging deployment on production-like hosts:

1. **Deploy staging** with its own MySQL database named `yas_staging_…`, its own RabbitMQ vhost, and TLS on ingress. Point the dispatchers at `yas-fakeprovider` on a staging host, or at Tigo's sandbox if Tigo provides one. Never point them at production Tigo.
2. **Run the system tests** from a host that can reach staging:
   ```bash
   SYSTEM_CALLBACK_URL=https://staging-host SYSTEM_CALLBACK_TOKEN=... \
   SYSTEM_PROVIDER_URL=https://fake-provider-host SYSTEM_OPS_URL=http://ops-host \
   SYSTEM_MYSQL_DSN='user:pass@tcp(db-host:3306)/yas_staging_system?parseTime=true&tls=true' \
   go test -tags system -count=1 -v ./tests/system
   ```
   The suite refuses any database not named `yas_test_` or `yas_staging_`. `TestSokaBoomFreeAnswer` needs `SYSTEM_BOOM_AWARD_CMD`; without it the test skips.
3. **Run the load generator** with `-allow-remote`, ramping `-n` and `-c` up to the expected peak and then beyond it. It only accepts `yas_test_` databases for seeding and progress, so give staging a `yas_test_load` database or seed it separately.
4. **Record thresholds** in `docs/load-baseline.md`: intake p99, end-to-end rate, and an empty dead-letter queue. This closes the checklist item "production-like infrastructure".

Still not covered by any tier: broker and database failover under load, soak runs over several hours, and real-provider contract checks.
