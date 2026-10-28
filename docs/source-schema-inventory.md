# Source schema inventory (code evidence)

This inventory describes table-creation scripts in the unchanged PHP repository. It is **not** a live database catalog. The scripts may be inactive, and deployed tables may differ. Obtain read-only `information_schema` metadata and `SHOW CREATE TABLE` before finalizing data movement.

## Dated families found in PHP DDL

| Source script | Table family | Key fields in script | Proposed destination |
|---|---|---|---|
| Soka and Ishikistaa `TriviaTransactionTable.php` | `vas_transaction_trivia_YYYYMMDD` | `ID`, `MO_ID`, `TXN_ID`, `MSISDN`, `AMOUNT`, `CHANNEL`, request/response, status, times | `payment_attempts`, `payment_events`; retain raw source for audit |
| Soka and Ishikistaa | `tbl_point_purchase_YYYYMMDD` | `ID`, `MO_ID`, `MSISDN`, `REQUEST_ID`, `AMOUNT`, `POINTS`, `STATUS` | `payment_attempts` plus `point_ledger`, linked only after event semantics are verified |
| Soka and Ishikistaa | `trivia_sms_logs_YYYYMMDD` | `ID`, `MO_ID`, `TXN_ID`, `MSISDN`, `TEXT`, request/response, status | `outbound_messages`, `outbound_message_events` |
| Soka and Ishikistaa | `trivia_mo_YYYYMMDD` | `ID`, `MSISDN`, `TEXT`, retry fields, `DATE` | `interactions`; retry history needs a separate mapping decision |
| Soka and Ishikistaa | `trivia_renewals_YYYYMMDD` | `ID`, `MSISDN`, `REQUEST`, `AMOUNT`, `TRANSACTION_UUID` | `payment_attempts` and possibly `subscription_events` after outcome verification |
| Soka and Ishikistaa | `tbl_payment_renewal_notifications_YYYYMMDD` | `ID`, `MSISDN`, status, details, `BILLED_AMOUNT`, `REQUEST_ID` | `payment_events`, `subscription_events` where supported |
| Soka and Ishikistaa | `tbl_payment_interaction_notifications_YYYYMMDD`, `tbl_payment_web_notifications_YYYYMMDD` | `ID`, `MSISDN`, status, details, `REQUEST_ID` | `payment_events`, tied to `payment_attempts` by verified request identity |
| Soka and Ishikistaa | `tbl_mpush_subs_init_notifications_YYYYMMDD`, `tbl_mpush_subs_init_activation_logs_YYYYMMDD` | `ID`, `MSISDN`, channel, `USSD_DATA`, request/response, callback or confirmation status | `activation_attempts`, `subscription_events` where supported |
| MegaPromo `TIGO/GenerateTable.php` | `tbl_promo_billing_YYYYMMDD` | `ID`, `BILLING_ID`, `MSISDN`, `MO_ID`, `AMOUNT`, `TXN_ID`, status and response | `payment_attempts`, `payment_events`, possibly `promo_entries` after gameplay rules are verified |
| MegaPromo | `tbl_payment_renewal_notifications_YYYYMMDD`, `tbl_mpush_subs_init_notifications_YYYYMMDD` | `ID`, `MSISDN`, request/status or channel | `payment_events`, `activation_attempts` |

The Soka/Ishikistaa renewal notification CREATE statement is present but its creation call is commented out in the inspected script. This is evidence of a proposed family, not evidence that tables exist. The two products also differ in the activation log status field (`CONFIRMED_STATUS` versus `CALLBACK_STATUS`).

## Mapping rules to verify against live data

1. **Historical identity:** IDs restart within each date-named table. Preserve `(product, source_table, legacy_id)` and source timestamps for every imported row. The current target `legacy_source`/`legacy_id` pairs support this only if `legacy_source` includes product and full table name. Do not deduplicate on `ID` alone.
2. **Subscriber number:** source `MSISDN` is generally `BIGINT`; target `subscribers.msisdn` is text. Confirm country prefix, leading zeros, and any nonnumeric values before formatting. Do not infer a country code.
3. **Money:** source `AMOUNT` is commonly `INT`; MegaPromo billing uses `DECIMAL(10,0)`. Target `amount_minor` requires a verified currency and source unit. Do not multiply or divide historical values yet.
4. **Time:** source mixes `TIMESTAMP` and `DATETIME`, usually without timezone metadata. Record the source connection timezone and midnight rollover rule before UTC conversion.
5. **Status and links:** source status text and `MO_ID`/`TXN_ID` are not guaranteed foreign keys. Build outcome maps and resolve links with sampled records; quarantine ambiguous or orphaned rows rather than inventing relationships.
6. **Text and encoding:** scripts mix `latin1` and `utf8` settings. Check actual table collations and validate SMS/question text before copying to UTF-8 targets.
7. **Capacity:** some source request IDs and status fields allow 1000 characters; target request IDs and status fields are shorter. Measure live maxima and define lossless overflow handling before any import.

## Read-only discovery needed

Collect MySQL version, databases and table definitions, row counts, minimum/maximum dates, distinct statuses and channels, request ID lengths/collisions, amount distributions, orphan counts, character sets, triggers, indexes, and active table families. Export only anonymized samples for provider contracts. No production data has been inspected or migrated.
