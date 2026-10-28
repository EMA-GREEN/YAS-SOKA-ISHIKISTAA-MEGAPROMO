#!/bin/sh
# Starts every YAS service in one container, restarting any that exits, as
# systemd's Restart=always would. Test stack only.
set -u
DB="yas:${YAS_DB_PASSWORD}@tcp(mysql:3306)"
export MYSQL_DSN="${DB}/yas_test_system?parseTime=true"
export AMQP_URL="amqp://yas:${YAS_AMQP_PASSWORD}@rabbitmq:5672/"
export EXPECTED_DB=yas_test_system
BIN=/opt/yas/bin
FAKE=http://127.0.0.1:18090

for db in yas_test_system yas_test_integration; do
  until MIGRATION_DB_DSN="${DB}/${db}?parseTime=true&multiStatements=true" $BIN/migrator -dir /opt/yas/migrations -apply -expected-db $db >/tmp/migrate-$db.log 2>&1; do
    echo "[stack] waiting to migrate $db"; sleep 2
  done
  echo "[stack] migrated $db"
done

# Every dispatcher talks to the fake provider and logs in for its token.
export TIGO_LOGIN_URL=$FAKE/api/auth/login TIGO_LOGIN_USERNAME=test TIGO_LOGIN_PASSWORD=test-only-password
export CHARGE_DISPATCH_ENABLED=true CHARGE_ENDPOINT=$FAKE/charge CHARGE_CP_ID=86 CHARGE_PASSWORD=test-only CHARGE_USERNAME=test CHARGE_SOURCE_ADDRESS=127.0.0.1
export SMS_DISPATCH_ENABLED=true SMS_ENDPOINT=$FAKE/sms SMS_CP_ID=86 SMS_PASSWORD=test-only SMS_USERNAME=test SMS_SOURCE_ADDRESS=127.0.0.1
export ACTIVATION_DISPATCH_ENABLED=true ACTIVATION_ENDPOINT=$FAKE/activate ACTIVATION_CP_ID=86 ACTIVATION_PASSWORD=test-only ACTIVATION_USERNAME=test ACTIVATION_SOURCE_ADDRESS=127.0.0.1
export RENEWAL_DISPATCH_ENABLED=true RENEWAL_MANAGE_SUBSCRIPTION_URL=$FAKE/renewal/manage RENEWAL_SUBS_MANAGE_URL=$FAKE/renewal/subs

service() {
  name=$1; shift
  ( while true; do
      env "$@" 2>&1 | sed "s/^/[$name] /"
      echo "[$name] exited; restarting"; sleep 1
    done ) &
}

service fakeprovider FAKE_LISTEN=0.0.0.0:18090 FAKE_ALLOW_REMOTE=true FAKE_CALLBACK_BASE=http://127.0.0.1:18080 FAKE_CALLBACK_TOKEN=$YAS_INGRESS_TOKEN $BIN/yas-fakeprovider
service callbacks HTTP_ADDR=0.0.0.0:18080 OPS_ADDR=0.0.0.0:19091 INGRESS_TOKEN=$YAS_INGRESS_TOKEN $BIN/callbacks
service relay OPS_ADDR=0.0.0.0:19092 $BIN/outbox-relay
service soka-mo OPS_ADDR=0.0.0.0:19093 $BIN/soka-mo-worker
service soka-points OPS_ADDR=0.0.0.0:19094 $BIN/soka-point-worker
service charge OPS_ADDR=0.0.0.0:19095 $BIN/soka-charge-dispatcher
service sms OPS_ADDR=0.0.0.0:19096 $BIN/soka-sms-dispatcher
service renewal OPS_ADDR=0.0.0.0:19097 $BIN/soka-renewal-dispatcher
service openq OPS_ADDR=0.0.0.0:19098 $BIN/soka-open-question-scheduler
service activation OPS_ADDR=0.0.0.0:19099 $BIN/soka-activation-dispatcher
service ishi-mo OPS_ADDR=0.0.0.0:19100 $BIN/ishikistaa-mo-worker
service ishi-pay OPS_ADDR=0.0.0.0:19101 $BIN/ishikistaa-payment-worker
echo "[stack] all services started"
wait
