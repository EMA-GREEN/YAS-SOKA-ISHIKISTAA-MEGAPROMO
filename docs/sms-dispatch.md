# Soka purchase SMS dispatch boundary

This component sends Soka point-purchase result and purchase-menu messages from `outbound_messages`. Its request follows `YAS-SOKA/SokaLib.php::interactiveRequest`: an `InteractiveRequest` operation, Dar es Salaam timestamp, MSISDN, message content, offer code, CP ID, and legacy MD5 digest of CP ID + password + timestamp. The PHP `updateSMSStatus` path treats status `0` or `0: Accepted for delivery` as provider acceptance. Credentials and endpoint are provided at runtime and are never stored in this repository. The current purchase templates are ASCII; parity for other message text and encoding is unverified.

The command is **disabled by default**. It requires `SMS_DISPATCH_ENABLED=true`, `EXPECTED_DB` matching `MYSQL_DSN`, `SMS_ENDPOINT`, `SMS_CP_ID`, `SMS_PASSWORD`, `SMS_USERNAME`, `SMS_BEARER_TOKEN`, and `SMS_SOURCE_ADDRESS`. HTTPS is required except for loopback test servers. It has not been run against a real SMS provider or production database.

## Duplicate-message rule

A pending message moves to `SENDING` with a stable request ID **before** the network call. A recognized success response moves it to `ACCEPTED` and records an outbound message event. This means provider acceptance only; it does not confirm delivery to a handset. Any other response or network error moves it to `NEEDS_REVIEW`. A crash after claim leaves `SENDING`. Neither state is automatically resent. Operators must reconcile the stable request ID with the provider before any manual retry. The dispatcher currently accepts only the two Soka purchase message kinds.

The isolated MySQL and fake-provider test verifies the request envelope, digest, content, offer, one accepted request and event, and no second send after an uncertain HTTP response. Real-provider response semantics, delivery receipts, timeout reconciliation, and any final delivery status remain open.
