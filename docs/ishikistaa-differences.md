# Ishikistaa compared with Soka

**Reference:**
- `YAS-ISHIKISTAA-SDP/IshikistaaMoConsumer.php`, `IshikistaaPaymentConsumers.php`, `IshikistaaLib.php` and `config.php`;
- `YAS-CALLBACKS/application/controllers/Ishikistaa.php`;
- the Soka equivalents.

The PHP code is a copy of Soka with changes, so this lists only the differences. Anything not listed behaves as in `docs/soka-answer-flow.md` and `docs/soka-subscription-flow.md`.

**Structure is the same as Soka.** In `IshikistaaMoConsumer.php` the `INACTIVE` block (lines 346–638) returns from its `staa`/`star` branch, so the play code inside it (about 374–627) is unreachable. The live active-subscriber branch starts at line 639.

## Configuration

| Item | Soka | Ishikistaa |
|---|---|---|
| Charge offer (`sendCharging`) | `017008600005`, or `017008600272` for BOOM | `017008600007` only; no BOOM billing |
| Interactive SMS and open-question offer | `017008600010` | `017008600009` |
| Golden hours | 18:00–23:59 | 18:00–21:59 (`GOLDEN_HR_END` 21) |
| Staff prefixes | five | `255715123`, `255713123` |
| Reactivation word | `soka` | `staa` or `star` |
| Unsubscribe words | `ondoa soka`, `ondoasoka`, `stop` | `ondoa ishikistaa`, `ondoaishikistaa`, `stop` |
| Points words | `pointi`, `points`, `point` | same |
| Help words | same six | same six |
| Messages | Soka texts | `FIRST_APPEND`, `NON_CUSTOMER`, `UNSUB_SMS`, `LOW_BALANCE`, `POINT_MSG`, `HELP_MSG`, `POINT_APPEND` (`Una pointi %s!`), `ONDOA`, `OPEN_QN_CONTENT` differ. `PURCHASE`, `LOW_BALANCE_PURCHASE`, `POINT_PURCHASE_MENU`, `ERROR_MSG` and the tier map are the same. |
| Request IDs the gateway routes elsewhere | `WW` web, `TS`, `A` Android, `RT`, `RP`, `W`/`RW`/`w` renewal | `WW` web, `A` Android, `GS` and **any `R` or `W`** renewal. Go IDs must avoid these letters. |
| Test offer traffic | `100000014600308` goes to a `_test` table | No test routing |

## Features Ishikistaa does not have

- Predict-and-Win, BOOM credits, BOOM awards, M-Push confirmation of the BOOM type.
- Dated level bonuses and `REWARD_MSG`. The level is not raised by answers or by point purchases.
- Rotating marketing text in questions.
- `tbl_mo_retry` retry rows. Go writes none for Soka either.

## Behaviour that differs

| Situation | Soka | Ishikistaa |
|---|---|---|
| `UN_SUBSCRIPTION` (provider `DeActivation`) | Renewal `UNSUBSCRIPTION`. If not subscribed, `NON_CUSTOMER`. Otherwise inactive, silently. | Handled **before** the subscription checks, for any MSISDN: inactive, `UNSUB_SMS`, renewal `UNSUB`, and a POST of `{"MSISDN"}` to a local `TIGO-ISHIKISTAA/notification/unsub_manage` endpoint (not in the repository). |
| `stop` keyword | Inactive and level reset, silent | Inactive and level reset, sends `UNSUB_SMS` |
| New subscriber via activation | Welcome is `FIRST_APPEND` (points) + question + terms link | `FIRST_APPEND` + question + `.`, with no points and no terms link |
| Inactive subscriber via activation | Reactivate, welcome with points | Reactivate, plain `question.` |
| Inactive subscriber's other text | `INACTIVE_SUB` | `NON_CUSTOMER`, sent with `sendSMS` |
| Unknown subscriber's MO | Inactive row and `INACTIVE_SUB` (Go's reading of an unknown column default) | `NON_CUSTOMER` via `sendSMS`, then an **active** subscription and a first question. **This subscribes the customer without a provider activation.** |
| Free-credit answer reply | `Unapoint {t}!KETE {n} Buree!{question}` | `Una pointi {t}!\n KETE {n} Buree!\n{question}` |
| Next question after a paid answer | `Unapoint {t}!{question}.` | `Una pointi {t}! {question}.` |
| Purchase menu and unknown-customer reply | Interactive SMS | `sendSMS`, a different SMS path |
| Low-balance detection | Case-insensitive `low balance` or `insufficient balance` | **Case-sensitive** `strpos`, so `Low balance` is treated as a billing error with no fallback |
| Fallback charge success | Points and next question | **No points and no next question.** The success check compares against the misspelled `LOW_BAANCE_FAILED`, so an answer that went through a fallback never matches. The customer has paid 45 or 30 TZS for nothing. |
| Renewal system for staff | Skipped | Sent only to the `subs-manage` endpoint |
| Payment failure after a fallback submit error | `QN_ERROR_MSG` with a new question | `ERROR_MSG` |

## Implemented in Go

**Shared engine.** Soka and Ishikistaa run the same MO and payment engines (`internal/workers/sokamo`, `internal/workers/sokapoints`). A `sokagame.Profile` holds the values in the tables above: offers, golden hours, staff list, words, messages and feature flags.

**Commands and queues.**
- `cmd/ishikistaa-mo-worker` reads `yas.ishikistaa.mo`: InteractiveResponse, Activation, RequestSendSms and DeActivation. Its metrics are on `127.0.0.1:9100`.
- `cmd/ishikistaa-payment-worker` reads `yas.ishikistaa.payments`, with metrics on `127.0.0.1:9101`.
- Each queue has its own dead-letter queue and consumer name.
- Ishikistaa M-Push events stay parked in `yas.ishikistaa.pending`. Any other unknown Ishikistaa kind is now unroutable.

**Dispatchers.** The charge, SMS and renewal dispatchers serve both products from one process each. Each uses the product's charge offers, SMS offer (`017008600009`) and renewal `serviceID` (`ISHIKISTAA`). The open-question scheduler handles both products' schedules.

**Decisions:**

| Topic | Go behaviour |
|---|---|
| Fallback success | A successful 45 or 30 TZS fallback **credits the answer and sends the next question**. PHP's misspelled `LOW_BAANCE_FAILED` check does neither. Customers already affected in PHP need an owner decision on credits or refunds. |
| Low-balance detection | **Case-insensitive**, as in Soka. Confirm against the provider's real reason text. |
| Unknown customer's MO | `NON_CUSTOMER` and an inactive record. PHP's auto-subscription without a provider activation is **not ported**. |
| Unsubscription notification | `UN_SUBSCRIPTION` (DeActivation) and `stop` set the subscription inactive, report `UNSUBSCRIPTION` to both renewal endpoints, and send `UNSUB_SMS`. The extra POST to `TIGO-ISHIKISTAA/notification/unsub_manage` is **not ported**; its contract is unknown. |
| SMS path | `sendSMS` replies (purchase menu, non-customer) go through the same InteractiveRequest dispatcher as everything else. |
| Request IDs | Go IDs are digits, `PU`, `F` and `FF`. None contains `A`, `R` or `W`, which the Ishikistaa gateway would route to Android or renewals. |

**Evidence:**
- **Profile unit tests:** `internal/workers/sokagame/profile_test.go`.
- **MySQL journey:** `internal/workers/sokamo/ishikistaa_integration_test.go` covers:
  - engine isolation from Soka;
  - the plain welcome, and staff reported only to `subs-manage`;
  - 22:30 not golden;
  - the Ishikistaa charge offer and request ID;
  - fallback crediting with no level change;
  - `M2` charged as an answer;
  - the free-credit layout;
  - `stop` with `UNSUB_SMS`, the inactive `NON_CUSTOMER` reply, and `staa` reactivation;
  - DeActivation of a subscriber and of a stranger;
  - the plain reactivating activation;
  - an unknown customer.
- **Topology test:** routes the Ishikistaa kinds and returns unknown ones.
- **SMS test:** checks the Ishikistaa offer.
- **Binary smoke run:** an Ishikistaa activation and answer went through the callback server, relay and both workers to an active subscription, two renewal requests, the welcome and a 99 TZS charge on `017008600007`.
- **Soka regression:** every Soka suite still passes.

**Not ported:**
- Ishikistaa M-Push activation.
- Renewal content, which as in Soka is not evidenced as live.
- Web payments.
- Ishikistaa reports.
