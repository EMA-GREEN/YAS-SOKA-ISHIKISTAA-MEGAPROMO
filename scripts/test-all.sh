#!/usr/bin/env bash
# Runs every test tier and builds reports/index.html:
#   static (gofmt, vet) -> unit (-race) -> stack up -> integration (incl. crash)
#   -> system journeys -> load -> report -> stack down
# Each tier records go test -json output under reports/raw. A failing tier is
# recorded and the run continues, so the report shows the whole picture; the
# script exits non-zero if any tier failed.
#
#   KEEP_STACK=1   leave the Compose stack running afterwards
#   LOAD_N=5000    callbacks for the load tier (0 skips it)
set -uo pipefail
cd "$(dirname "$0")/.."
ROOT=$(pwd)
RAW=$ROOT/reports/raw
COMPOSE="docker compose -f deploy/compose/compose.yml"
# Runs share one stack and one reports/raw, so only one may run at a time.
LOCK=$ROOT/reports/.test-all.lock
mkdir -p "$ROOT/reports"
if ! mkdir "$LOCK" 2>/dev/null; then
  echo "another test-all.sh run is in progress (remove $LOCK if it is stale)" >&2
  exit 2
fi
trap 'rmdir "$LOCK"' EXIT
mkdir -p "$RAW"
rm -f "$RAW"/*
failed=()
started=$(date -u +%Y-%m-%dT%H:%M:%SZ)

log() { printf '\n== %s\n' "$*"; }
record() { # tier name, exit status
  echo "{\"tier\":\"$1\",\"status\":$2,\"finished\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\"}" >> "$RAW/tiers.jsonl"
  [ "$2" -eq 0 ] || failed+=("$1")
}

# Secrets for the throwaway stack; never written to the repository.
rnd() { LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c "$1"; }
export YAS_DB_ROOT_PASSWORD=${YAS_DB_ROOT_PASSWORD:-$(rnd 24)}
export YAS_DB_PASSWORD=${YAS_DB_PASSWORD:-$(rnd 24)}
export YAS_AMQP_PASSWORD=${YAS_AMQP_PASSWORD:-$(rnd 24)}
export YAS_INGRESS_TOKEN=${YAS_INGRESS_TOKEN:-$(rnd 40)}

log "static checks"
unformatted=$(gofmt -l . | grep -v '^deploy/compose/' || true)
if [ -n "$unformatted" ]; then echo "gofmt needed: $unformatted"; record gofmt 1; else record gofmt 0; fi
go vet ./... && go vet -tags system ./tests/system; record vet $?

log "unit tests (race detector)"
go test -race -count=1 -json ./... > "$RAW/unit.json"; record unit $?

log "build binaries and start the stack"
case "$(docker info --format '{{.Architecture}}')" in aarch64|arm64) GOARCH_STACK=arm64 ;; *) GOARCH_STACK=amd64 ;; esac
rm -rf deploy/compose/bin deploy/compose/migrations && mkdir -p deploy/compose/bin
CGO_ENABLED=0 GOOS=linux GOARCH=$GOARCH_STACK go build -tags timetzdata -o deploy/compose/bin/ ./cmd/... &&
  cp -r migrations deploy/compose/migrations &&
  $COMPOSE down -v --remove-orphans >/dev/null 2>&1
start_stack() { $COMPOSE up -d --build --wait mysql rabbitmq rabbitmq-it && $COMPOSE up -d --build app; }
# A broker occasionally exits during first boot; one clean retry covers it.
if ! start_stack; then
  for c in mysql rabbitmq rabbitmq-it; do docker logs yas-test-$c-1 > "$RAW/first-start-$c.log" 2>&1 || true; done
  $COMPOSE down -v --remove-orphans >/dev/null 2>&1
  start_stack
fi
stack=$?
ready=1
if [ $stack -eq 0 ]; then
  for i in $(seq 1 90); do
    ready=0
    for p in $(seq 19091 19101); do
      [ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:$p/readyz)" = 204 ] || ready=1
    done
    [ $ready -eq 0 ] && break
    sleep 2
  done
fi
record stack $((stack + ready))
docker logs yas-test-app-1 > "$RAW/stack-start.log" 2>&1 || true

DSN_BASE="yas:${YAS_DB_PASSWORD}@tcp(127.0.0.1:13306)"
log "integration tests (MySQL, RabbitMQ, crash recovery)"
TEST_MYSQL_DSN="${DSN_BASE}/yas_test_integration?parseTime=true" \
TEST_AMQP_URL="amqp://yas:${YAS_AMQP_PASSWORD}@127.0.0.1:15673/" \
  go test -count=1 -p 1 -timeout 20m -json -run 'Integration|Broker' ./internal/... > "$RAW/integration.json"
record integration $?

log "system journeys against the running stack"
SYSTEM_CALLBACK_URL=http://127.0.0.1:18080 SYSTEM_CALLBACK_TOKEN=$YAS_INGRESS_TOKEN \
SYSTEM_PROVIDER_URL=http://127.0.0.1:18090 SYSTEM_OPS_URL=http://127.0.0.1 \
SYSTEM_MYSQL_DSN="${DSN_BASE}/yas_test_system?parseTime=true" \
SYSTEM_BOOM_AWARD_CMD="docker exec -e 'MYSQL_DSN=yas:${YAS_DB_PASSWORD}@tcp(mysql:3306)/yas_test_system?parseTime=true' -e EXPECTED_DB=yas_test_system yas-test-app-1 /opt/yas/bin/soka-boom-credit-award -round %s -apply" \
  go test -tags system -count=1 -timeout 15m -json ./tests/system > "$RAW/system.json"
record system $?

LOAD_N=${LOAD_N:-5000}
if [ "$LOAD_N" -gt 0 ]; then
  log "load: $LOAD_N callbacks through the full stack"
  LOADGEN_MYSQL_DSN="${DSN_BASE}/yas_test_system?parseTime=true" \
    go run ./cmd/yas-loadgen -seed -subscribers 200 -n "$LOAD_N" -c 50 -wait 600s \
    -token "$YAS_INGRESS_TOKEN" -run "LOAD$(date +%H%M%S)" | tee "$RAW/load.txt"
  record load "${PIPESTATUS[0]}"
fi

docker logs yas-test-app-1 > "$RAW/stack.log" 2>&1 || true
sed -i.bak "s/${YAS_DB_PASSWORD}/<redacted>/g; s/${YAS_AMQP_PASSWORD}/<redacted>/g; s/${YAS_INGRESS_TOKEN}/<redacted>/g" "$RAW"/*.log "$RAW"/*.json "$RAW"/*.txt 2>/dev/null
rm -f "$RAW"/*.bak

log "report"
echo "{\"started\":\"$started\",\"finished\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\",\"commit\":\"$(git rev-parse --short HEAD)\",\"go\":\"$(go env GOVERSION)\",\"host\":\"$(uname -sm)\"}" > "$RAW/meta.json"
go run ./cmd/yas-report -raw "$RAW" -features tests/features.json -out reports/index.html; record report $?

if [ "${KEEP_STACK:-0}" != 1 ]; then $COMPOSE down -v --remove-orphans >/dev/null 2>&1; fi

if [ ${#failed[@]} -gt 0 ]; then
  echo "FAILED tiers: ${failed[*]}  (see reports/index.html)"
  exit 1
fi
echo "All tiers passed. Report: reports/index.html"
