# Soka BOOM credit awards and open questions

## BOOM credit award

**Reference:** `BoomCustomerBaseLoad.php`, `BoomCustomerCreditAwardPublisher.php`, `BoomCustomerCreditAwardConsumer.php` and `BoomCustomerCreditAwardMessageHandler.php`.

**How PHP runs it:**
1. A loader with an unknown schedule copies every `CUSTOMER_TYPE='BOOM_CUSTOMER'` subscriber into `tbl_boom_customer_base`. This includes inactive subscribers.
2. A publisher sends each row to `boom_cust_credit_award`.
3. Ten consumers set `BOOM_CREDIT = 1`, rather than adding one, and send `BOOM_CREDIT_NOTIFY_SMS`.

Reloading the base grants the credit again. The handler also passes an undefined access token to `SokaLib`.

**Go:** `cmd/soka-boom-credit-award -round <name> -apply` runs one round in batches. It:
- needs `EXPECTED_DB`, and without `-apply` only checks its configuration;
- gives every `BOOM` subscription, inactive ones included as in PHP, `boom_credits = 1`;
- records one `credit_awards` row per subscription and round, and one `BOOM_CREDIT_NOTIFY` message.

Rerunning a round, or resuming after a crash, skips subscriptions already awarded, so a credit the customer has already spent is not granted again. A new round name grants again. The schedule must be confirmed with the owner, then run from cron or a systemd timer.

| Difference from PHP | Why |
|---|---|
| Idempotent per round. | A rerun of the loader re-granted spent credits. |
| The notification goes through the guarded InteractiveRequest SMS dispatcher. PHP uses `sendSMS`, a different SMS path. | Only one SMS adapter is ported. Confirm that the channel difference is acceptable. |

## Open questions

**Reference:** `SokaOpenQuestionScheduler.php`, `SokaOpenQuestionPubliser.php`, `SokaOpenQuestionConsumer.php`, `sendOpenQuestion()` in `SokaMoConsumer.php` and `SokaLib::sendopenQuestion`.

**Two triggers:**
1. **The provider replies that no interactive session is open.** The MO carries the reason *No open question for the subscriber* or *no interactive request sent to subscriber today*. The MO worker answers with `OPEN_QN_CONTENT` plus the current question, whatever the subscription state, as PHP does. PHP also sends to MSISDNs it has no record of, with empty points and question; Go needs a subscription and otherwise ignores the MO.
2. **A schedule is due.** `cmd/soka-open-question-scheduler` polls every 5 seconds, as PHP does. For each schedule due today in Dar es Salaam, it creates one `open_question_deliveries` row and one `OPEN_QUESTION` message per eligible subscription. The message is the schedule text, then a space, then the current question, with `%msisdn` and `%s` (points) filled in. Batches commit under the schedule row's lock and resume after a crash, and the schedule becomes `PROCESSED` with its recipient count. Schedules from earlier days stay pending, as in PHP.

**Audiences:** active, not do-not-disturb, and then:

| Audience | Window |
|---|---|
| `ALL` | everyone |
| `PLAYING` | last successful charge, subscription start, or last reactivation within 7 days |
| `TOP` | any of those three between 59 and 7 days ago |

The messages use the same InteractiveRequest API and offer (`017008600010`) as the existing SMS dispatcher, which now sends `OPEN_QUESTION`. Accented characters are folded as in `SokaLib::convert`.

| Legacy defect | Go |
|---|---|
| The `TOP` query uses the undefined `$plaingbaseDate`, so it matches nobody. | Uses the intended 7-day boundary. |
| `TOP`'s `OR` clauses are not parenthesized, so they escape the active and do-not-disturb filters. | Parenthesized. |
| A schedule that inserts no rows stays `PENDING` and is retried every 5 seconds. Only the newest pending schedule is ever picked, so it blocks the others that day. | Every due schedule is processed in turn, and an empty one is closed with 0 recipients. |
| `utf8_encode` on text that is already UTF-8 double-encodes it before folding. | Folds accents only. |
| PHP compares a date to a timestamp string for the 7-day window, which in effect drops the seventh day. | Compares timestamps. |

Legacy `openquestion_schedule` times are local. When migrating, convert them to the UTC `scheduled_for`.

## Not portable from this repository

**Web payments.** `SokaWebPaymentConsumers.php` settles `WW` web charges and their answers. Whatever creates those charges and answers is not in this repository, so Go has nothing to match the payments to. The web front end's source or API contract is needed.

## Evidence

- **Award test:** `internal/workers/sokaboom` covers BOOM-only grants (inactive included), batching, a rerun after the credit was spent, and the next round setting the credit to 1.
- **Scheduler test:** `internal/workers/sokaopen` covers each audience window, do-not-disturb and inactive exclusion, schedules from yesterday and later today, the message body, a rerun, and closing an empty schedule.
- **MO test:** the answer journey now checks the open-question reply.
