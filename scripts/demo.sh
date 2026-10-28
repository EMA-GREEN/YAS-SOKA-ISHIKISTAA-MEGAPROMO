#!/usr/bin/env bash
# Starts a fresh yas-test stack, seeds it with the demo data set (cmd/yas-seed),
# and leaves it running. Connection details go to deploy/compose/demo.env,
# which git ignores and only you can read. Stop it with:
#   docker compose -f deploy/compose/compose.yml down -v
# scripts/test-all.sh replaces a running demo stack with its own.
#
#   DEMO_CROWD=40   extra active Soka players for volume
set -uo pipefail
cd "$(dirname "$0")/.."
ROOT=$(pwd)
. scripts/stack.sh
LOCK=$ROOT/reports/.test-all.lock
mkdir -p "$ROOT/reports"
if ! mkdir "$LOCK" 2>/dev/null; then
  echo "a test-all.sh or demo.sh run is in progress (remove $LOCK if it is stale)" >&2
  exit 2
fi
trap 'rmdir "$LOCK"' EXIT

rnd() { LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c "$1"; }
export YAS_DB_ROOT_PASSWORD=$(rnd 24) YAS_DB_PASSWORD=$(rnd 24) YAS_AMQP_PASSWORD=$(rnd 24) YAS_INGRESS_TOKEN=$(rnd 40)
ENV_FILE=deploy/compose/demo.env
(umask 077; cat > "$ENV_FILE" <<EOF
# Demo stack credentials, valid until the stack is torn down.
YAS_DB_PASSWORD=$YAS_DB_PASSWORD
YAS_AMQP_PASSWORD=$YAS_AMQP_PASSWORD
YAS_INGRESS_TOKEN=$YAS_INGRESS_TOKEN
SEED_MYSQL_DSN=yas:$YAS_DB_PASSWORD@tcp(127.0.0.1:13306)/yas_test_system?parseTime=true
EOF
)

echo "== building and starting the stack"
mkdir -p reports/demo
start_stack reports/demo || { echo "stack did not start; see: docker logs yas-test-app-1" >&2; exit 1; }

echo "== seeding demo data through the running services"
SEED_MYSQL_DSN="yas:${YAS_DB_PASSWORD}@tcp(127.0.0.1:13306)/yas_test_system?parseTime=true" \
  go run ./cmd/yas-seed -token "$YAS_INGRESS_TOKEN" -crowd "${DEMO_CROWD:-40}" || exit 1

cat <<EOF

== the demo stack is running
Callbacks     http://127.0.0.1:18080   (header X-Callback-Token, see $ENV_FILE)
Fake Tigo     http://127.0.0.1:18090/requests?msisdn=255799700010   (every charge and SMS)
Metrics       http://127.0.0.1:19091/metrics … 19101
RabbitMQ UI   none; inspect queues with: docker exec yas-test-rabbitmq-1 rabbitmqctl list_queues
Database      docker exec -it yas-test-mysql-1 mysql -uyas -p yas_test_system   (password in $ENV_FILE)

Subscribe a new number (Tigo's activation callback):  scripts/demo-send.sh soka 255799700140 activate
Then answer as that subscriber:                        scripts/demo-send.sh soka 255799700140 A
Stop and delete everything:       docker compose -f deploy/compose/compose.yml down -v
EOF
