# Soka subscription lifecycle and free credits

**Reference:** the `SUBSCRIPTION` and `UN_SUBSCRIPTION` branches, the unknown, inactive and active subscriber branches, and `sendRenewal()` in `YAS-SOKA/SokaMoConsumer.php`, plus `SokaTrivia.php` for how callbacks become request types.

## Callbacks the MO worker now handles

`yas.soka.point-mo` receives `soka.InteractiveResponse`, `soka.Activation`, `soka.RequestSendSms` and `soka.DeActivation`. The relay removes the earlier holding-queue bindings for the last three.

| Callback | PHP | Go |
|---|---|---|
| `Activation` or `RequestSendSms` with `SubscriptionStatus=A` | `SUBSCRIPTION`. The text is `BOOM_CUSTOMER-SOKA` when `CustomerType` is `BOOM_CUSTOMER`. | Same |
| `Activation` or `RequestSendSms` without status `A` | Stores an MO with no text, which the MO worker then **charges as an answer** for active subscribers | Ignored |
| `DeActivation` | `UN_SUBSCRIPTION` | Same |
| Any of these with offer code `100000014600308` | Stored in `tbl_interaction_mo_notifications_test`, which nothing reads | Ignored |

## Behavior

- **Subscription:**
  - Non-staff subscribers are reported to the renewal system.
  - **New:** an `ACTIVE` subscription is created, `BOOM` for BOOM customers with one BOOM credit, otherwise `NORMAL`. The welcome question is `Una point 0. PAPO HAPO unabahatika na SOKA;{question}. Vigezo na masharti bofya …`. BOOM customers also get `BOOM_CREDIT_NOTIFY`.
  - **Already active:** a plain `{question}.` is sent.
  - **Inactive:** the subscription is reactivated, the BOOM segment and credit are reset as PHP does, and the welcome shows the current points.
- **Unsubscription:**
  - `DeActivation`, `ONDOA SOKA`, `ondoasoka` or `STOP` reports `UNSUBSCRIPTION` to the renewal system, even for staff, as PHP does.
  - The subscription becomes `INACTIVE` with level 0, and nothing is sent.
  - A `DeActivation` for someone with no active subscription gets `NON_CUSTOMER`.
- **Keywords:** points, help and `ondoa` answer in any subscription state, because PHP checks them before the inactive branch.
- **Inactive subscriber:** `SOKA` reactivates them, reports to the renewal system and sends a question with the points. Any other text gets `INACTIVE_SUB`.
- **Unknown subscriber:** an inactive `NORMAL` subscription is created and they receive `INACTIVE_SUB`.
- **Staff:** MSISDNs containing `255715123`, `255713123`, `255658123`, `255673123` or `255678123` skip the renewal system on subscription and are marked do-not-disturb.
- **Free and BOOM credits:**
  - Before charging an answer, a BOOM credit is spent first, then a normal credit.
  - The answer is scored with golden hours but no level bonus and is credited at once (`FREE_ANSWER`). The level is unchanged.
  - The reply is `Unapoint {total}!KETE {remaining} Buree!{question}`, with the `KETE` part only when credits remain.
- **Every change** is a `subscription_events` row (`SUBSCRIBED`, `REACTIVATED`, `UNSUBSCRIBED`) keyed by the callback.

## Renewal system

`sendRenewal()` posts `{"msisdn","serviceID":"SOKA","activityType"}` to two internal endpoints over plain HTTP, and only logs the response. Go records one `renewal_requests` row per endpoint, `MANAGE_SUBSCRIPTION` and `SUBS_MANAGE`, in the same transaction as the subscription change.

`cmd/soka-renewal-dispatcher` sends them:
- It is disabled unless `RENEWAL_DISPATCH_ENABLED=true` and `EXPECTED_DB` matches.
- It claims only the targets whose URL is set (`RENEWAL_MANAGE_SUBSCRIPTION_URL`, `RENEWAL_SUBS_MANAGE_URL`).
- It requires HTTPS.
- It marks a 2xx response `SENT` and anything else `NEEDS_REVIEW`, and it **never resends**. The renewal system may start or stop recurring charges, so a duplicate could charge or cancel twice.
- It serves metrics on `127.0.0.1:9097`.

The real endpoints are plain HTTP, so they need a transport decision before this dispatcher can reach them.

## M-Push subscription init

**Reference:** `SokaMpushYasSubsInitNotificationConsumer.php`, `SokaLib::sendMpushYasSubscriptionActivation` and `SokaServices::confirmMpushSubsInitActivation`.

**Flow:**
1. Go receives `soka-mpush/notification` callbacks (`docs/legacy-process-matrix.md`, section 7). Their `soka.MpushSubsInit` events now go to the MO worker instead of the holding queue.
2. The MO worker records one `activation_attempts` row per request, with state `PENDING`, request ID `MP{callback}{msisdn}`, and the channel and USSD data. A customer without a subscription gets an inactive one.
3. When the provider's `Activation` callback arrives, it confirms the newest unconfirmed attempt from today or yesterday (Dar es Salaam), as PHP does. An attempt confirmed before it was sent is never sent.

**The guarded `cmd/soka-activation-dispatcher`:**
- **Request:** it posts PHP's `ACTIVATE` request: offer `017008600010`, the MD5-signed CP password, and the token in `X-Authorization: Bearer`.
- **Guards:** it is disabled unless `ACTIVATION_DISPATCH_ENABLED=true` and `EXPECTED_DB` matches. It requires HTTPS.
- **Outcome:** a 2xx response with `responseParam.status` 0 is `SENT`; anything else is `NEEDS_REVIEW` with the provider's status and code. Nothing is resent. PHP sends at once and records whatever comes back; activation may start billing, so Go does not guess.
- **Metrics:** served on `127.0.0.1:9099`.

PHP reads the access token from `user_access_token`, which `UserAccessToken.php` refreshes. That script is not in any check script, so its schedule is unknown. Go takes the token from `ACTIVATION_ACCESS_TOKEN` until the token refresh is ported. Ishikistaa and MegaPromo M-Push events stay parked.

## Deliberate differences

| PHP | Go | Why |
|---|---|---|
| An unknown subscriber's MO creates a `tbl_qn_subscribers` row whose state depends on the column default (unknown), sends a first question and `INACTIVE_SUB`, and queues `soka_subs_activate`. | Creates an `INACTIVE` subscription and sends `INACTIVE_SUB` only. | The invitation makes sense only if the default is inactive, and the activation pipeline that reads `soka_subs_activate` is not in any check script. **Confirm the column default from the live schema.** |
| A purchase keyword from an inactive or unknown subscriber reactivates them and sends terms and `INACTIVE_SUB`, without charging. | Sends `INACTIVE_SUB` only. | Reactivating on a purchase attempt, then telling the customer to send SOKA, is contradictory. |
| An inactive subscriber's `SOKA` gets `FIRST_APPEND` with a literal `%s`. | The points are filled in. | Legacy bug. |
| A credit holder's `P300` is spent as a wrong free answer, because credits are checked before purchase keywords. | Purchase and menu keywords are checked first. | Legacy bug. |
| Using a BOOM credit also reduces normal `CREDIT`. | Only the credit used is reduced. | Legacy bug. |
| A BOOM credit answer also sends a zero-amount BOOM usage charge to the provider. | Not sent. | Needs the BOOM provider contract. |
| One BOOM notification sits after a `return` and never sends. | Sent whenever a BOOM customer subscribes or reactivates. | The reachable branch sends it. |

## Evidence

- **Lifecycle test:** `internal/workers/sokamo/subscription_integration_test.go`. It covers:
  - ignored activations;
  - new, BOOM, active, reactivating and staff activations;
  - the BOOM free answer, then a charged answer;
  - normal credits;
  - silent `STOP`;
  - inactive keywords, invitation and purchase;
  - `SOKA` reactivation;
  - `DeActivation` of a subscriber and of a stranger;
  - an unknown customer's MO.
- **Dispatcher test:** `internal/renewaldispatch` covers one send per endpoint, the request body, review on a failure response, no resend, unconfigured targets left pending, and plain HTTP refused.
- **Crash test:** `internal/e2e` adds ten new-customer activations while processes are killed. Each produced exactly one subscription, one event, two renewal requests and one welcome.
