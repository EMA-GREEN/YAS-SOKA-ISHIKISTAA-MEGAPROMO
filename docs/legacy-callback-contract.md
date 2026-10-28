# Legacy callback contract: code characterization

This contract is derived from the unchanged PHP source, not from production traffic. It covers the three JSON notification controllers only. The Go tests use synthetic subscriber numbers and requests; no customer data is included.

## Receipt path

| Product | PHP source | Route | Observable result after insert |
|---|---|---|---|
| Soka | `YAS-CALLBACKS/application/controllers/SokaTrivia.php` | `/notification/soka-trivia/billing`, `/soka-trivia/notification` | `requestId`, inserted `responseId`, 14-digit local timestamp, `operation: CP_NOTIFICATION`, status `0`, code `0000`, description `Success` |
| Ishikistaa | `YAS-CALLBACKS/application/controllers/Ishikistaa.php` | `/ishikistaa/notification` | Same response fields |
| MegaPromo | `YAS-CALLBACKS/application/controllers/MegaPromo.php` | `/notification/megapromo` | Same response fields for `Type=Payment` |

The controllers read `requestParam.data` as named values. Payment status is `FAILED` for the literal string `"null"`, an empty string, or numeric JSON zero. A nonempty `Reason` other than the PHP-empty string `"0"` also means `FAILED`; otherwise the PHP branch calls it `SUCCESS`. Because PHP uses strict comparison, the string `"0"` reaches `SUCCESS`; the characterization test records this edge case. Missing or JSON-null `ChargeAmount` is classified `UNKNOWN` in Go to avoid a false success.

Request ID substring checks are ordered and case sensitive. Soka checks `WW`, `TS`, `A`, `RT`, `RP`, uppercase `W`, then lowercase `w`; Ishikistaa checks `WW`, `A`, `GS`, then `R` or `W`; MegaPromo checks `W` or `w` before `RMPR`. These checks select a channel and either the interaction or renewal storage stream. They do not establish a reliable provider identity scheme; a later worker must validate actual examples before using them for financial posting.

The PHP callback controllers insert into notification tables. Separate RabbitMQ publishers and consumers perform payment and gameplay effects. Soka/Ishikistaa consumers read `msisdn`, `txnstatus`, `paymentdetail`, `requestid`, `billingSystem`; the MegaPromo consumer reads `msisdn`, `paymentStatus`, `paymentDetails`, `requestID`, `billedAmount`. The current Go relay publishes a new queue format and does not feed these PHP consumers.

## Intentional safety differences in Go

- Empty or malformed JSON receives a client error; PHP echoes `Empty Request` for an empty body and has unchecked array access for malformed payloads.
- Go acknowledges only after the callback and outbox transaction commits. PHP may emit a success-shaped response even when a database insertion fails, depending on gateway behavior.
- Go deduplicates identical retries and rejects a changed body with the same callback identity. PHP inserts each receipt without this uniqueness rule in the inspected controllers.
- Go accepts a core payment envelope for safe receipt but does not infer that a payment has settled or award points/prizes. Product workers remain unimplemented. The legacy MegaPromo controller only records `Type=Payment`; for other types it returns a success-shaped response with a null `responseId`, while the current Go intake stores the event. This remains an unresolved parity difference and must not drive product effects.

The PHP controllers contain hard-coded test-number exceptions that bypass normal persistence; those are excluded from the synthetic contracts pending deployment evidence. Tests cover response shape, parser behavior, branch precedence, status classification, retry deduplication, and atomic rollback. They are characterization tests for code evidence; they do not prove production provider behavior or deployed routes.
