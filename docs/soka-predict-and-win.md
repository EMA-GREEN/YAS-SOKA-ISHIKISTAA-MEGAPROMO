# Soka Predict-and-Win

**Reference:** `YAS-SOKA/PredictAndWin.php`, which the running MO and payment consumers call before any other check, and the `PREDICT_*` constants in `config.php`.

## Conversation

`PREDICT` or `M` followed by digits is predict traffic. It is never charged as an answer.

| MO | Requires | Result |
|---|---|---|
| `PREDICT` | active subscription | Today's campaign and published match day, listing scheduled fixtures the subscriber has not already predicted: `Ubashiri wa leo: 1. A v B 2. C v D`, or the match day's own list text. Starts a 24-hour session (`LIST_SENT`). |
| `M{slot}`, e.g. `M2` | session `LIST_SENT` or `SUBMITTED`; slot in range; not already predicted; before kickoff | `FIXTURE_CHOSEN` |
| `M{home}{sep}{away}` with `-`, `,`, `;` or `:` | session `FIXTURE_CHOSEN`; active; before kickoff; not already predicted | A `PENDING_PAYMENT` prediction and a 99 TZS `PREDICTION` charge with request ID `PD{callback}{msisdn}` on the segment's offer. The session becomes `BILLING_PENDING`, and nothing is sent until the payment result. |
| `M2 1` (space only) | — | `PREDICT_INVALID_SCORE` |
| anything else | — | `PREDICT_WRONG_STATE` |

- **No session:** the customer gets `PREDICT_HINT` ("send PREDICT first").
- **Expired session:** it is deleted and they get `PREDICT_EXPIRED`.
- **Blocked fixtures:** a fixture with a prediction in `PENDING_PAYMENT`, `CONFIRMED`, `WON` or `LOST` cannot be predicted again.
- **Slot range:** as in PHP, the highest slot counts every scheduled fixture, but the list shows only those still open.

## Payment

`PD`, and the older `PW`, request IDs are routed before the `PU` purchase branch, as PHP does.

| Outcome | Prediction | Session | SMS |
|---|---|---|---|
| Success, with matching amount and billing ID | `CONFIRMED` | `SUBMITTED`, so another fixture can be chosen | `PREDICT_RECEIVED` |
| Low balance | `LOW_BALANCE_FAILED` | back to `FIXTURE_CHOSEN` for the same fixture | `PREDICT_LOW_BALANCE` |
| Other failure | `PAYMENT_FAILED` | back to `FIXTURE_CHOSEN` | `PREDICT_ERROR` |

No points, no fallback charge.

## Differences and gaps

| PHP | Go | Why |
|---|---|---|
| If the charge HTTP call itself fails, the prediction becomes `PAYMENT_FAILED` at once and the customer gets an error SMS. | The charge is an intent. An uncertain dispatch goes to manual review, and the prediction stays `PENDING_PAYMENT` until the payment callback arrives or a reviewer acts. | Same no-guess rule as other charges. |
| Sessions and submissions are keyed by MSISDN. | Keyed by subscriber. Unknown MSISDNs get a subscriber row. | Matches the target schema. |
| `WON` and `LOST` are set by code outside this repository. | The statuses exist, but no grading is ported. | Match results and winner selection need the owner's process. |
| Fixtures, match days and campaigns are maintained elsewhere. | Migration 010 provides the tables. No admin tool is included. | Not in the repository. |

## Evidence

**Parser unit tests:** `internal/workers/sokagame`.

**MySQL journey:** `internal/workers/sokamo/predict_integration_test.go`. It covers:
- the hint before `PREDICT`;
- the list, with a cancelled fixture excluded;
- invalid-slot, after-kickoff, wrong-state and spaced-score replies;
- choosing a fixture;
- a score with its pending charge;
- the low-balance reopen;
- a confirmed retry with no points;
- the already-predicted block and the shortened list;
- an expired session;
- no published day;
- an inactive subscriber;
- no answer charge for any of this traffic.
