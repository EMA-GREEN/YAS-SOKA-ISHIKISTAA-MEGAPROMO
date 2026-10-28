# MegaPromo (Testi Zali) behaviour and the decisions it needs

**Reference:** `MEGAPROMO-APP/TIGO`, which contains:
- `TigoMegaPromoMoInteractionController.php`, `TigoMegaPromoPaymentController.php`, `TigoMegaPromoMobileMoneyController.php`, `TigoMegaPromoRenewalController.php`, `MegaPromoLib.php` and `TigoMegaPromoDatabaseServices.php`;
- `keywords.config`, `app.config` and `messages.config`;
- `processTigoPesa.php`.

The callback side is `YAS-CALLBACKS/.../MegaPromo.php` and `MegaPromoCollection.php`.

MegaPromo is not a trivia product. Customers buy tickets (*KETE*) and play them. Each play is an entry, and fixed entry positions win cash. **No Go code acts on MegaPromo events yet.** Its callbacks are stored in the Go inbox and parked in `yas.megapromo.pending`. Prize and money rules must be signed off before they are ported.

## Kept-running processes

| Process | Workers | Role |
|---|---|---|
| `TigoMegaPromoMoPublisher`, `TigoMegaPromoMoconsumers` | ≥20 | MO classification, play, purchase, unsubscribe |
| `TigoMegaPromoPaymentNotificationPublisher`, `TigoMegaPromoPaymentCallbackConsumer` | 10 | Charge results: credit tickets or decide a paid play |
| `TigoMegaPromoTigPesaPushPublisher`, `TigoMegaPromoTigoPesaPushConsumer` | 5 | Send queued TigoPesa payment requests |
| `TigoMegaPromoRenewalContentConsumers` | 5 | Grant free plays from the renewal system and send renewal content |

## MO classification

`keywords.config` lists are matched as **substrings** (`strpos`) of the lowercased, trimmed text, in this order:
1. **unsubscribe**, e.g. `ondoa`, `stop`, `toa`, `acha`, `exit`;
2. **balance**, e.g. `bal`, `kete`, `ket`, `salio`;
3. **play**: `t`, `tt`, `win`, `zali`, `zari`, `zaali`, `zaari`;
4. **help**;
5. **subscribe**, e.g. `testizali`, `sali`;
6. **purchase**, e.g. `zali1`, `win7`, `zari30`.

A number after a balance or play keyword turns the MO into a purchase of that many tickets.

**Consequences, faithful but surprising:**
- **The letter `t` is a play keyword.** Any text containing a "t" that does not first match unsubscribe or balance is a **play**. That includes the subscribe word `testi zali` and most free text.
- **Substrings catch ordinary words.** `toa` and `acha` inside ordinary words unsubscribe the customer. `ket` inside words reads as a balance check.
- **Price points are substrings too.** `1`, `7` and `30` are matched with `strpos`, so `zali10` or `zali17` is a valid "1" purchase. The configured amounts are `amount_1`=300, `amount_7`=1800 and `amount_30`=6000 TZS. An unconfigured count falls back to a 300 TZS direct play.

## Playing

| Subscriber | Result |
|---|---|
| Unknown | Calls the **BOOM customer API**. Creates an active subscriber, reports `SUBSCRIPTION` to the renewal system, and sends `WELCOME_SMS` and `SUB_SMS`. A BOOM customer gets one free credit and plays it at once; anyone else is charged 300 TZS (`MPR…`, "PLAY"). |
| Credit > 0 (`CREDIT_ENABLED`) | Uses a credit and plays (`RENEWAL_CREDIT`) |
| Tickets > 0 | Uses a ticket and plays (`CREDIT`) |
| Otherwise | Charges 300 TZS. The play happens when the payment callback succeeds. |

Inactive subscribers are reactivated by playing and re-reported to the renewal system.

## How a play wins

Each play inserts a row into `tbl_promo_entries`, or `tbl_promo_entries_test` for `TEST_MSISDN`. **The row's auto-increment ID is the entry's position.**

1. **Draw positions:** if the ID is a multiple of 1,200,000, 3,000,000 or 5,000,000, the row is marked for the big draw (`PROCESS_STATUS='ENABLE'`). The function returns at once, so that customer has spent a ticket or paid but **gets no SMS** and no counters are updated.
2. **Fixed winners:** otherwise the entry wins when `SELECT SUM(AMOUNT), ID FROM tbl_promo_winning_positions WHERE {id} % POSITION = 0` returns a position.
   - **Winning:** the prize is the sum of every position dividing the ID. A row goes into `tbl_promo_fixed_winners`, a `PENDING` row into `tbl_promo_fixed_winnersdisbursement_process`, and the customer gets `WIN`.
   - **Losing:** the customer gets one of `LOST_1`…`LOST_10` in rotation.
3. **Counters:** a `grems_txn` row and subscriber counters are recorded either way.

**Issues with this scheme:**
- **The query may fail on MySQL 8.** It mixes `SUM()` with a bare `ID` and has no `GROUP BY`, which MySQL's default `ONLY_FULL_GROUP_BY` rejects. On such a server every play errors. The live `sql_mode` decides what customers actually experience.
- **Outcomes depend on the auto-increment sequence.** Any migration, replay, or second writer changes who wins. Moving this to Go requires an explicit, monotonic position counter carried over from the live value, not a new table's IDs.
- **`WON_AMOUNT` is never set.** It is only set when `$smsType === "WON"`, a variable that is undefined there.
- **Payout is not in this repository.** Only the `PENDING` disbursement rows are written.

## Paying

**Charge callbacks:** `MegaPromo.php` routes requests containing `W` to renewals and `RMPR` to retries. Everything else is an interaction.
- **Purchase success:** 300, 1,800 or 6,000 TZS adds 1, 7 or 30 tickets, valid for 12 months, and sends `SUCCESS_PURCHASE`. Any other amount leaves the ticket count undefined.
- **Paid play success:** records the entry and decides win or lose as above.
- **Failure:** `LOW_BALANCE` when the reason contains `Billing Failure due to low balance for Msisdn`, otherwise `ERROR`.

**TigoPesa / Mixx push:**
- **Creation:** `processTigoPesa.php` inserts a push request from **unauthenticated query-string parameters**: `AMOUNT`, `MSISDN`, `tickets` and `txnID`.
- **Sending:** the push consumer sends requests created today to TigoPesa.
- **Callback:** the push callback (now received by Go, `docs/legacy-process-matrix.md` section 7) credits the request's `TICKET_COUNT`, **not a count derived from the amount paid**.
- **Risk:** a crafted request could push 1 TZS for 1,000 tickets. As written, `processTigoPesa.php` uses an undefined `$db` and should fail before inserting. Confirm it is not web-reachable, and remove it.

**Renewal content:** `TigoMegaPromoRenewalContentConsumers` receives `{msisdn, count}` from the renewal system's queue. It adds `count` free credits and sends `RENEWAL_DEFAULT_CONTENT` to active, enticement-on subscribers. In `processRenewalContent` the time window from `AUTO_RENEWAL_MESSAGE_SCHEDULE_START`/`END` is not applied.

**Templates:** messages are read from a database template table (`getMessageTemplates`) by type. `messages.config` appears to be a copy, but the live table is the source of truth.

## Decisions needed before porting

1. **Prize rules.** Confirm the fixed-position modulus rule, the three draw multiples, and how prize sums combine. Supply the live `tbl_promo_winning_positions`, the current entry counter, and the `sql_mode`. Decide how the big draw is run and who approves it.
2. **Keyword matching.** Keep substring matching, which makes the single letter `t` a play, or change to whole-word matching. Changing it alters which customers are charged.
3. **Price points.** Keep substring matching (`zali17` counts as `1`) or require exact counts.
4. **TigoPesa.** Who creates push requests (the USSD menu?), and should tickets be derived from the amount paid rather than trusted from the request?
5. **Payout.** Where `PENDING` disbursements are paid from, and whether Go must integrate with it.
6. **The no-SMS draw entry.** Should draw-position entries also get a message?
7. **BOOM customer API.** Its contract and credentials. The code also hardcodes a test MSISDN as a BOOM customer.

## What Go already provides for MegaPromo

- **Callback intake:** `notification/megapromo` (JSON), `mpush`, and the TigoPesa/Mixx push callback.
- **Parking:** those events are stored durably and parked in `yas.megapromo.pending` for replay once the rules are approved.
- **Reuse:** the shared dispatchers, ingress, observability and outbox already apply.
