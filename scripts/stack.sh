# Shared by test-all.sh and demo.sh; source it from the repository root.
# start_stack LOGDIR builds Linux binaries, replaces any running yas-test stack
# with a fresh one, and waits until every service reports ready. It returns
# non-zero if the stack did not come up. The YAS_* secrets must be exported.

COMPOSE="docker compose -f deploy/compose/compose.yml"

compose_up() { $COMPOSE up -d --build --wait mysql rabbitmq rabbitmq-it && $COMPOSE up -d --build app; }

start_stack() {
  local logdir=$1 arch status ready i p
  case "$(docker info --format '{{.Architecture}}')" in aarch64|arm64) arch=arm64 ;; *) arch=amd64 ;; esac
  rm -rf deploy/compose/bin deploy/compose/migrations && mkdir -p deploy/compose/bin
  CGO_ENABLED=0 GOOS=linux GOARCH=$arch go build -tags timetzdata -o deploy/compose/bin/ ./cmd/... &&
    cp -r migrations deploy/compose/migrations || return 1
  $COMPOSE down -v --remove-orphans >/dev/null 2>&1
  # A broker occasionally exits during first boot; one clean retry covers it.
  if ! compose_up; then
    for c in mysql rabbitmq rabbitmq-it; do docker logs yas-test-$c-1 > "$logdir/first-start-$c.log" 2>&1 || true; done
    $COMPOSE down -v --remove-orphans >/dev/null 2>&1
    compose_up || return 1
  fi
  for i in $(seq 1 90); do
    ready=0
    for p in $(seq 19091 19101); do
      [ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:$p/readyz)" = 204 ] || ready=1
    done
    [ $ready -eq 0 ] && return 0
    sleep 2
  done
  return 1
}
