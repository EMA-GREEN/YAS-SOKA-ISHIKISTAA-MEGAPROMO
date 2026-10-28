# Soka charge dispatch boundary

This component turns a `charge_intents` row into one provider HTTP request. It follows the request shape in `YAS-SOKA/SokaLib.php::sendCharging`: `PU` request ID, Dar es Salaam timestamp, `Payment` operation, offer code, subscriber, reference ID, charge amount, CP ID, and the legacy MD5 digest of CP ID + password + timestamp. Credentials and endpoint are supplied at runtime and are never stored in the repository or logged.

The command is **disabled by default**. It requires `CHARGE_DISPATCH_ENABLED=true`, `EXPECTED_DB` matching `MYSQL_DSN`, `CHARGE_ENDPOINT`, `CHARGE_CP_ID`, `CHARGE_PASSWORD`, `CHARGE_USERNAME`, `CHARGE_BEARER_TOKEN`, and `CHARGE_SOURCE_ADDRESS`. HTTPS is required except for loopback test servers. The command has not been run against a provider or production database.

## Duplicate-charge rule

The dispatcher changes a pending intent to `SUBMITTING` and commits **before** making a network request. A 2xx HTTP response moves it to `SUBMITTED`, meaning only that the request received an HTTP acceptance; it does not mark the payment successful. A non-2xx result or network error moves it to `NEEDS_REVIEW`. A crash after claim leaves `SUBMITTING`. Neither state is automatically sent again. Operators must reconcile uncertain requests by the stable provider request ID before deciding on any manual action. The payment callback remains the authority for customer points.

The isolated test uses a fake local provider to verify request shape, digest, offer and amount, one submission on success, and no repeat after an uncertain HTTP response. It does not prove the real provider treats request IDs as idempotency keys, that its HTTP response body signals acceptance, or that a callback always follows. Those contract and reconciliation checks remain open.
