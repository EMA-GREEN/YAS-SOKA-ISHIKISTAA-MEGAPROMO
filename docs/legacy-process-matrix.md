# Legacy process and effect matrix

Derived from the PHP source at `/Users/dany/Projects/work/YAS-SOKA-ISHIKISTAA-MEGAPROMO` on 2026-10-28.

**What counts as running:** a script is treated as *kept running* when a current (non `-OG`) `*Check.sh` loop restarts it. A script is *started by script* when it has its own launcher `.sh` but is not in a check loop. All other scripts are *not evidenced*. The repository has no crontab, systemd unit or deployed process list, so reports, resets and renewal scripts have no known schedule. The deployed host must confirm all of this. Host addresses and credentials are deliberately not recorded here.

## 1. HTTP entry points (`YAS-CALLBACKS`, CodeIgniter 3)

| Route | Controller | Writes (legacy) | Go status |
|---|---|---|---|
| `notification/soka-trivia/billing`, `soka-trivia/notification` | `SokaTrivia/notification` | `tbl_interaction_mo_notifications`; `tbl_payment_{interaction,web,soka_renewal}_notifications_YYYYMMDD`; `tbl_payment_retry_mo_notifications` | JSON intake done; Soka point-purchase path done |
| `ishikistaa/notification` | `Ishikistaa/notification` | `tbl_mo_notifications`; `tbl_payment_{interaction,web,ishikistaa_renewal}_notifications_YYYYMMDD`; `tbl_payment_renewal_mo_notifications` | JSON intake done; no workers |
| `notification/megapromo` | `MegaPromo/notification` | `tbl_payment_{interaction,retry}_notifications`; `tbl_payment_zali_renewal_notifications_YYYYMMDD` | JSON intake done; no workers |
| `soka-mpush/notification`, `ishikistaa-mpush/notification`, `megapromo-mpush/notification` | `*Mpush/notification` | `tbl_mpush_subs_init_notifications_YYYYMMDD` | **Intake done** (`internal/callbacks/extra.go`); activation worker pending |
| `notification/megapromo/tigopesa/collection` | `MegaPromoCollection/tigopesa` (XML) | `tbl_promo_subscribers`, `tbl_promo_mobilemoney_transaction_logs` in the **`tigo_megapromo_test`** database | **Not ported.** It does not work as written; see section 7. |
| `notification/megapromo/tigopesapush/collection`, `…/mixxpush/collection` | `MegaPromoCollection/tigopesapush` (JSON) | `tbl_promo_subscribers`, `tbl_promo_mt_logs`, `tbl_promo_mobilemoney_push_transactions`, `tbl_promo_tigopesapush_callbacknotif` | **Intake done**; ticket-credit worker pending |
| `notification/megapromo/tigopesapush/testcollection` | same as above | same, publicly reachable | Do not port |
| `notification/megapromo-test` | `MegaPromo/processNotification` (public) | same as `notification/megapromo` | Do not port |
| `test/123`, `test/insert` | `SokaTrivia/testLogging` (missing), `TestInsert` (missing) | — | Do not port |
| `notification/{user-optin,user-optout,user-renewed,mt/dn,mo,subscription/charge}/*` | `Notification` (**not in repo**) | unknown | Needs deployed code |
| `boa-trivia`, `boli`, `somatrivia`, `soma-trivia`, `supa-trivia` notifications | controllers **not in repo** | unknown | Out of scope unless deployed |

`SokaTrivia` contains a provider-test forward to another service. It is disabled because the MSISDN match has a `-disabled` suffix, and it must not be ported.

## 2. Kept-running processes

The PHP publishers poll a table (`IS_OUTGOING='1'`) about every second, publish to `triviaExchange` (Soka and Ishikistaa) or `megapromo_Exchange`, then mark the row. Consumers ack after processing. The worker counts are the check-script targets.

### Soka (`YAS-SOKA`, database `trivia_tigotz_vas`)

| Process | Kind | Input → output | External effects | Go status |
|---|---|---|---|---|
| `SokaMoNotificationPublisher` | publisher | `tbl_interaction_mo_notifications` → `tigo_soka_mo_router` | — | Replaced by outbox relay |
| `SokaMoConsumer` ×15 | consumer | MO: `QUERY_POINTS`, `SUBSCRIPTION`, `UN_SUBSCRIPTION`, `ondoa`, `soka` reactivation, answer to the current question (golden-hour scoring, levels and rewards), BOOM credit play, point-purchase keywords and menu, Predict-and-Win | Tigo Payment charge; InteractiveRequest SMS; open question; renewal subscription request | **Point purchase, paid and free-credit answers, keyword replies, subscription lifecycle with renewal-system intents, Predict-and-Win** (`docs/soka-predict-and-win.md`) (`internal/workers/sokamo`, `docs/soka-answer-flow.md`, `docs/soka-subscription-flow.md`) |
| `SokaPaymentNotificationPublisher` | publisher | `tbl_payment_*` (SMS channel) → `tigo_soka_payment_router` | — | Replaced by outbox relay |
| `SokaPaymentConsumers` ×50 | consumer | payment result for answer charges, point purchases, BOOM; updates `vas_transaction_trivia*`, `tbl_qn_answers`, `tbl_qn_subscribers`, `tbl_point_purchase_*`; low-balance fallback via `tbl_mo_retry` | follow-up charge; result SMS; `sms_reprocess` on failure | **Point purchase, answer, and prediction charges, including the low-balance ladder** (`internal/workers/sokapoints`) |
| `SokaWebPaymentNotificationPublisher`, `SokaWebPaymentConsumers` ×5 | pair | web channel payment results → answers and subscriber state | — | **Blocked:** the web app that creates these charges is not in the repo |
| `SokaSmsReprocessPublisher`, `SokaSmsReprocessConsumer` ×5 | pair | `sms_reprocess` due today → resend `trivia_interaction_mt_logs` text | InteractiveRequest SMS | **Done** in the SMS dispatcher: one delayed same-day resend after an explicit refusal; uncertain sends still go to review (`docs/sms-dispatch.md`) |
| `SokaOpenQuestionScheduler` | scheduler | due `openquestion_schedule` → `tbl_openquestion_notifications` for active, non-DND subscribers in playing, top or all segments | — | **Done** (`cmd/soka-open-question-scheduler`, `docs/soka-boom-and-open-questions.md`) |
| `SokaOpenQuestionPubliser`, `SokaOpenQuestionConsumer` ×10 | pair | notifications → open question SMS and `tbl_openquestion_logs` | open question SMS | **Done**, replaced by the scheduler and SMS dispatcher |
| `BoomCustomerCreditAwardPublisher`, `BoomCustomerCreditAwardConsumer` ×10 | pair | `tbl_boom_customer_base` → set `BOOM_CREDIT=1` and notify | SMS | **Done** as the idempotent `cmd/soka-boom-credit-award` |
| `SokaMpushYasSubsInitNotificationPublisher`, `…Consumer` ×5 | pair | `tbl_mpush_subs_init_notifications_*` → activation | M-Push YAS activation | **Done:** activation intents in the MO worker plus the guarded `cmd/soka-activation-dispatcher` |

### Soka, started by script only

These have a launcher `.sh` but no check loop, and their publishers have no launcher at all. They may run from elsewhere or not at all.

| Process | Input | Effects |
|---|---|---|
| `SokaSubscriptionConsumer` | `tigo_soka_mosubscription_router` | question SMS, `trivia_reminder_logs` |
| `SokaNewRenewalConsumer` | `tigo_soka_renewal_new_router` | renewal content, renewal and fallback transaction and middleware tables |
| `TigoSokaMoRetryPaymentNotificationConsumers` | `tigo_soka_mo_retry_payment_router` | retry-payment outcomes to answers and subscribers |

### Ishikistaa (`YAS-ISHIKISTAA-SDP`, database `ishikistaa_tigo_vas`)

| Process | Kind | Input → output | Effects | Go status |
|---|---|---|---|---|
| `IshikistaaMoNotificationPublisher`, `IshikistaaMoConsumer` ×10 | pair | MO gameplay, subscription, points query, M-Push activation callback | charge, SMS, open question | Pending |
| `IshikistaaPaymentNotificationPublisher`, `IshikistaaPaymentConsumers` ×30 | pair | payment results → answers, point purchase, subscriber | follow-up charge, SMS | Pending |
| `IshikistaaOpenQuestionScheduler`, `…Publisher`, `…Consumer` ×5 | scheduler and pair | as for Soka | open question SMS | Pending |
| `IshikistaaMpushYasSubsInitNotificationPublisher`, `…Consumer` ×5 | pair | M-Push init | M-Push activation | Pending |
| `IshikistaaSubscriptionConsumer`, `IshikistaaNewRenewalConsumers` | started by script | subscription and renewal queues | SMS, renewal | Pending |

This folder also holds copies of the `SokaMpushYasSubsInit*` publisher and consumer, which use the **Soka** queue. No Ishikistaa check script runs them. Confirm they are not started on that host, or they would compete with the Soka consumers.

### MegaPromo / Testi Zali (`MEGAPROMO-APP/TIGO`, database `tigo_megapromo`)

| Process | Kind | Input → output | Effects | Go status |
|---|---|---|---|---|
| `TigoMegaPromoMoPublisher`, `TigoMegaPromoMoconsumers` ≥20 | pair | MO → `TigoMegaPromoMoInteractionController`: play, subscribe, unsubscribe, entries (`tbl_promo_entries`), draw (`tbl_promo_draw`), fixed winners (`tbl_promo_fixed_winners`, disbursement process) | charge, SMS | Pending. Prize rules need owner sign-off. |
| `TigoMegaPromoPaymentNotificationPublisher`, `TigoMegaPromoPaymentCallbackConsumer` ×10 | pair | payment result → `tbl_promo_billing*`, entries, winner check | SMS | Pending |
| `TigoMegaPromoTigPesaPushPublisher`, `TigoMegaPromoTigoPesaPushConsumer` ×5 | pair | `tbl_promo_mobilemoney_push_transactions` → TigoPesa push | **mobile-money collection request** | Pending. Financial reconciliation is required before this moves. |
| `TigoMegaPromoRenewalContentConsumers` ×5 | consumer | renewal content queue → `TigoMegaPromoRenewalController` | SMS, renewal transactions | Pending |

## 3. Not evidenced as running

These are reports and email (`*Report*`, `Revenue*`, `MailerScript`, `*Analysis*`, `TotalMessage`), resets and maintenance (`RESET-CREDIT`, `ResetOpenQuestionTable`, `ResetLevel`, `STRING-APPEND`, `InsertScript`, `*PendingInsert`, `*UpdatePendig`, `GenerateTable`), renewal fetch and scripts (`*Renewal*Fetch`, `*RenewalScript`, `Renew`), retry and reprocess chains (`TigoSokaMoRetry*`, `TigoSokaMoReprocess*`, `SokaWebPaymentReprocess*`, `TigoMegaPromo*Retry*`), activation (`Tigo*ActivationPublisher` and `…Consumer`, `WebActivation`), older copies (`*-OG.php`, `*Old.php`, `IshkistaaMoConsumer`) and tests (`*Test*`, `boom_customer_credit_test`, `openquestiontest`). `GenerateTable` and the daily `_YYYYMMDD` table names suggest a scheduled table-creation job. **The deployed crontab must be obtained before any of these are dropped.**

## 4. External effects

| Effect | Legacy implementation | Go status |
|---|---|---|
| Tigo Payment charge | `*Lib::sendCharging` (MD5 CP password, offer codes) | Soka point purchase only; guarded, no auto-retry |
| Interactive SMS (InteractiveRequest) | `*Lib::interactiveRequest`, question and open-question senders | Soka purchase SMS only; guarded |
| Kannel `sendsms` | reports and some MO paths | Pending |
| M-Push YAS subscription activation | `*Lib::sendMpushYasSubscriptionActivation` | Soka done (guarded); Ishikistaa and MegaPromo pending |
| Renewal content and subscription requests | `*Lib::sendRenewalContent`, `sendRenewal` | Soka `sendRenewal` done as guarded `renewal_requests` and `cmd/soka-renewal-dispatcher`; renewal content pending |
| BOOM credit award | `BoomCustomerCreditAwardMessageHandler`, BOOM token API | Pending |
| TigoPesa / Mixx push collection | `TigoMegaPromoMobileMoneyController` | Pending |
| Email reports | `MailerScript` (PHPMailer) | Out of scope until the owner confirms |

## 5. Defects and risks found in the legacy code

1. **SQL built by string interpolation.** `*Services.php` methods such as `getSubscriberDetails`, `transactionDetails`, `update` and `_update` embed MSISDNs, request IDs and other callback-derived values directly into SQL. Values from unauthenticated callbacks reach these queries, which is a plausible attack vector. Every Go query is parameterized.
2. **No callback authentication**, publicly routed test endpoints, and routes to controllers that are missing from the repo. The Go server fails closed (`docs/operations.md`) and ports no test routes.
3. **Messages can be lost.** None of the 40 publishers sets persistent delivery or uses publisher confirms, and they mark rows as sent after an unconfirmed publish. A broker restart or crash can drop billing and MO events. The Go outbox uses persistent messages, confirms, a lease and dead letters, and the crash test covers this.
4. **TLS verification is disabled** (`CURLOPT_SSL_VERIFYPEER false`) in 9 files that call providers. Go adapters require HTTPS with verification.
5. **Daily tables** are chosen by `date("Ymd")` at request time, so a callback received just after midnight lands in the next day's table. The Go inbox has no date-named tables.
6. **Credentials are hardcoded:** the Tigo Payment CP password (`SokaLib.php`) and the RabbitMQ `trivia_manage` password (in the consumers) are in plain text. Treat both as compromised and rotate them. The payment endpoint is plain HTTP on a private address, which the Go dispatcher refuses until a transport decision is made.
7. In `SokaMoConsumer.php`, the `INACTIVE` block (lines 585–1037) contains an unreachable older copy of the answer flow. Using a BOOM credit also decrements normal `CREDIT`. Error and timeout paths send a new question without updating `LAST_QN_ID`. See `docs/soka-answer-flow.md`.
8. A `tigopesa` collection path writes to a `*_test` database, and copies of the Soka M-Push publisher and consumer sit in the Ishikistaa folder.

## 6. Behaviors chosen for Go and their tests

| Behavior | Decision | Test |
|---|---|---|
| Callback envelope, success response shape, request-ID identity | Preserve | `internal/callbacks/*_test.go` |
| Ordered payment-result branches | Preserve | `internal/legacycontract/payment_test.go` |
| Soka point-purchase keywords, tiers, BOOM offer, menu for near-miss keywords | Preserve | `internal/workers/sokamo/processor_integration_test.go` |
| Points per tier and result message | Preserve | `internal/workers/sokapoints/processor_integration_test.go` |
| Legacy charge request shape | Preserve; never auto-retry after an uncertain result | `internal/chargedispatch` |
| Legacy InteractiveRequest shape | Preserve; uncertain sends go to review instead of `sms_reprocess` resend | `internal/smsdispatch` |
| At-least-once delivery with idempotent consumers | Fix: replaces unconfirmed, non-persistent publish | `internal/relay`, `internal/e2e` |
| Callback ingress | Fix: fail-closed policy | `internal/ingress` |
| Test routes, disabled forwards, `*_test` database writes | Drop | Absent by design |

Rows marked *Pending* in sections 1–4 have no Go test yet. Each needs its own PHP-derived scenario suite before it is implemented.

## 7. Additional callback routes in Go

| Route | Legacy behavior | Go behavior | Deviation to confirm |
|---|---|---|---|
| `*-mpush/notification` | Every error is HTTP 200 with `status:1` and codes 1001 (empty), 1002 (invalid JSON), 1003 (missing msisdn or channel), 1004 (channel not SMS, USSD or APP). Each request inserts a row. MegaPromo ignores `ussd_data`. | Same replies and codes. Stored durably before success. | There is no provider request ID. Identity is product plus normalized content within one Dar es Salaam day, so an identical retry the same day is stored once, where PHP stored duplicates. A non-string `msisdn` gets 1003. |
| `tigopesapush/collection`, `mixxpush/collection` | Replies success **before** processing, so a later failure loses the result. Builds the reply by string concatenation, so the provider's reference is not escaped. Deduplicates on `ReferenceID`. Credits tickets and sends SMS inline. | Same reply fields, sent only after the result is stored; a storage failure returns 503 so the provider can retry. The reply is JSON-encoded. `ReferenceID` is the identity, and a changed payload for the same reference gets 409. Crediting moves to a worker. | `Status` must be a JSON boolean or the string `true`/`false`, because PHP's loose truth test cannot be matched safely for money. Other values get 400. |
| `tigopesa/collection` (XML) | Writes to `tigo_megapromo_test`. Looks up the subscriber with an undefined variable (`$moble`), so it always takes the new-subscriber path. Replies with malformed XML and `RESULT TS` regardless of errors. | Not served (404). | The owner must confirm whether any provider calls it. If so, its real contract needs a redacted sample. |

These defects were found in the legacy push callback and are fixed by design in Go: a new subscriber's `subscriber_details` row was written from the wrong, empty array, and the Kannel SMS URL embedded gateway credentials.
