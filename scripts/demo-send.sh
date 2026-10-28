#!/usr/bin/env bash
# Sends one subscriber message to the demo stack and shows what the fake Tigo
# provider received for that number a few seconds later.
#   scripts/demo-send.sh soka|ishikistaa MSISDN TEXT
set -euo pipefail
cd "$(dirname "$0")/.."
[ $# -eq 3 ] || { echo "usage: $0 soka|ishikistaa MSISDN TEXT" >&2; exit 2; }
case "$1" in soka) route=/soka-trivia/notification ;; ishikistaa) route=/ishikistaa/notification ;; *) echo "product must be soka or ishikistaa" >&2; exit 2 ;; esac
case "$2" in 2557997*) ;; *) echo "use a synthetic demo number (2557997…)" >&2; exit 2 ;; esac
[ -r deploy/compose/demo.env ] || { echo "no demo stack; run scripts/demo.sh first" >&2; exit 2; }
token=$(sed -n 's/^YAS_INGRESS_TOKEN=//p' deploy/compose/demo.env)
text=${3//\\/\\\\}; text=${text//\"/\\\"}
since=$(curl -s "http://127.0.0.1:18090/requests?msisdn=$2" | python3 -c 'import json,sys; print(len(json.load(sys.stdin) or []))')

body="{\"requestId\":\"DEMO-$(date +%s)-$RANDOM$RANDOM\",\"requestParam\":{\"data\":[{\"name\":\"Msisdn\",\"value\":\"$2\"},"
if [ "$(printf '%s' "$3" | tr 'A-Z' 'a-z')" = activate ]; then
  body+="{\"name\":\"Type\",\"value\":\"Activation\"},{\"name\":\"SubscriptionStatus\",\"value\":\"A\"}]}}"
else
  body+="{\"name\":\"Type\",\"value\":\"InteractiveResponse\"},{\"name\":\"CustomerResponse\",\"value\":\"$text\"}]}}"
fi
printf 'callback: '
curl -s -w ' (HTTP %{http_code})\n' -H 'Content-Type: application/json' -H "X-Callback-Token: $token" \
  --data "$body" "http://127.0.0.1:18080$route"
sleep 4
echo "provider received since then:"
curl -s "http://127.0.0.1:18090/requests?msisdn=$2" | python3 -c '
import json, sys
skip = int(sys.argv[1])
for r in json.load(sys.stdin)[skip:]:
    f = r.get("fields") or {}
    detail = f.get("Content") or f.get("ChargeAmount", "") and "charge " + f["ChargeAmount"] + " TZS" or f.get("activityType", "")
    print(" ", r["kind"].ljust(15), detail)
' "$since"
