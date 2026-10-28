# Soka SMS dispatch boundary

This component sends the Soka message intents in `outbound_messages`: purchase results and menus, questions, rewards, keyword replies, subscription messages, open questions, and Predict-and-Win replies. Its request follows `YAS-SOKA/SokaLib.php::interactiveRequest`: an `InteractiveRequest` operation, Dar es Salaam timestamp, MSISDN, message content, offer code, CP ID, and legacy MD5 digest of CP ID + password + timestamp. The PHP `updateSMSStatus` path treats status `0` or `0: Accepted for delivery` as provider acceptance. Credentials and endpoint are provided at runtime and are never stored in this repository. The current purchase templates are ASCII; parity for other message text and encoding is unverified.

The command is **disabled by default**. It requires `SMS_DISPATCH_ENABLED=true`, `EXPECTED_DB` matching `MYSQL_DSN`, `SMS_ENDPOINT`, `SMS_CP_ID`, `SMS_PASSWORD`, `SMS_USERNAME`, `SMS_BEARER_TOKEN`, and `SMS_SOURCE_ADDRESS`. HTTPS is required except for loopback test servers. It has not been run against a real SMS provider or production database.

## Duplicate-message rule

A pending message moves to `SENDING` with a stable request ID **before** the network call. A recognized success response moves it to `ACCEPTED` and records an outbound message event. This means provider acceptance only; it does not confirm delivery to a handset. **Explicit refusal:** a 2xx response whose `responseParam.status` is not `0` is a refusal, and the SMS was not taken. This replaces `sms_reprocess` and `SokaSmsReprocessConsumer.php`:
- On the first send, and only on the Dar es Salaam day the message was created, the message goes back to `PENDING` with `next_attempt_at` 5 seconds later and a `REJECTED_RETRY` event.
- The resend uses request ID `IR{id}{msisdn}`, a new ID as PHP's resend has.
- A second refusal, or a refusal of an older message, ends as `REJECTED`.

**Anything else** moves the message to `NEEDS_REVIEW`: a timeout, a transport error, a non-2xx answer, or an unparsed body. A crash after claim leaves `SENDING`. Neither state is resent automatically. Operators must reconcile the stable request ID with the provider before any manual retry.

PHP also resends after timeouts and other uncertain results, where the customer may already have the first copy. Go does not.

The isolated MySQL and fake-provider test verifies:
- the request envelope, digest, content and offer;
- one accepted request and event;
- no second send after an uncertain HTTP response;
- one delayed resend with a new request ID after a refusal;
- `REJECTED` after two refusals, with no third send;
- no resend of a refusal from an earlier day. Real-provider response semantics, delivery receipts, timeout reconciliation, and any final delivery status remain open.
