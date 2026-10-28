YAS-INTERACTIONS — Architecture & process guide (PHP → Go revamp)
================================================================
Audience: Golang developer revamping interaction + callback processing.
Scope: Soka, Ishikistaa, MegaPromo / Testi Zali (not YAS-RENEWALS; similar RabbitMQ/DB patterns exist there).

1. HIGH-LEVEL PICTURE
---------------------
Three parallel VAS trivia/interaction stacks + one shared callback gateway.

  [YAS / Tigo network]  MO SMS, billing callbacks, M-Push, web pay, renewals
           |
           v
  YAS-CALLBACKS (CodeIgniter, HTTP JSON in)
    - Validates/parses CP_NOTIFICATION payloads
    - Persists raw + normalized rows (often daily tables: tbl_payment_*_YYYYMMDD)
    - Routes by service controller (SokaTrivia, Ishikistaa, MegaPromo, *Mpush)
           |
           |  (async) DB polling publishers OR direct queue publish from interaction apps
           v
  RabbitMQ (per-service exchanges/queues)
           |
           v
  YAS-SOKA | YAS-ISHIKISTAA-SDP | MEGAPROMO-APP/TIGO
    - Long-running PHP consumers (many worker processes)
    - Business logic: subscribe, charge, Q&A, points, prizes, SMS MT, renewals side-effects

Interaction apps SEND charge requests (Tigo PAY/Payment API) to MSISDN; final charge result
returns asynchronously via YAS-CALLBACKS, then publishers move work to queues for consumers.

2. REPOSITORY LAYOUT
--------------------
YAS-INTERACTIONS/
  YAS-CALLBACKS/          CodeIgniter — inbound billing/MO/M-Push HTTP callbacks
  YAS-SOKA/               Soka interaction workers (PHP CLI + AMQP)
  YAS-ISHIKISTAA-SDP/     Ishikistaa interaction workers
  MEGAPROMO-APP/TIGO/     MegaPromo / Testi Zali (Zali) interaction workers

Key PHP libraries per service:
  - *Lib.php       Charging, SMS, token, Tigo API helpers
  - *Services.php  DB access (publisher vs consumer credentials)
  - config.php / DbConfig.php / app.config (INI) — queues, workers, SMS templates, URLs
  - vendor/        php-amqplib

Production ops: *Check.sh scripts loop every ~5s, pgrep PHP scripts, nohup restart if down
(or spawn N consumer processes if count < threshold).

3. END-TO-END FLOWS (conceptual)
--------------------------------
A) MO trivia (Soka / Ishikistaa example)
   1. Subscriber sends SMS keyword/answer → MO ingested → queued (MO publisher/consumer chain).
   2. MoConsumer parses message, eligibility, DND, subscription state.
   3. On billable action: SokaLib/IshikistaaLib.sendCharging() → Tigo Payment API (async).
   4. Tigo POSTs billing result → YAS-CALLBACKS (e.g. soka-trivia/notification).
   5. Callback stores row → PaymentNotificationPublisher polls DB → RabbitMQ message.
   6. PaymentConsumers (pool) ack message → processMessage(): SUCCESS/FAIL → points, next question,
      prize logic, MT SMS via Kannel/sendsms URLs.

B) MegaPromo / Zali
   - MO: TigoMegaPromoMoPublisher → MO queue → TigoMegaPromoMoconsumers (MoInteractionController).
   - Play/charge via MegaPromoLib; callbacks → payment/retry/renewal tables.
   - TigoPesa push: separate publisher + consumer pool.
   - Renewal content: TigoMegaPromoRenewalContentConsumers (renewal SMS/content pipeline).
   - Config: MEGAPROMO-APP/TIGO/app.config (exchange megapromo_Exchange, queue names below).

C) M-Push YAS subscription init (all three services)
   - Dedicated publisher + consumer pair; activates offer via ACT/Activate URL (see app.config).

D) Open questions (Soka / Ishikistaa)
   - Scheduler + Publisher push scheduled questions; OpenQuestionConsumer serves playing users.

4. YAS-CALLBACKS (HTTP entry)
-----------------------------
Routes (application/config/routes.php) — examples:
  soka-trivia/notification          → SokaTrivia/notification
  ishikistaa/notification         → Ishikistaa/notification
  notification/megapromo            → MegaPromo/notification
  soka-mpush/notification           → SokaMpush/notification
  ishikistaa-mpush/notification     → IshikistaaMpush/notification
  megapromo-mpush/notification      → MegaPromoMpush/notification
  notification/megapromo/tigopesa/* → MegaPromoCollection (TigoPesa)

Controllers log raw JSON, decode requestParam.data (Msisdn, TransactionId, status fields),
insert into service-specific tbl_payment_* tables (many are date-partitioned by Ymd).

Gateway models: TigoSokaGatewayServices, TigoRenewalGatewayServices, TigoMegaPromoServices, etc.

Go target: single callback service with pluggable handlers per product; idempotent on requestId;
structured logging; no daily table names hard-coded without migration plan.

5. SOKA — processes (YAS-SOKA/)
-------------------------------
Monitored by SokaPublisherCheck.sh (singleton publishers) + SokaConsumerCheck.sh (worker pools).

PUBLISHERS (expect 1 process each):
  Script                              Role
  ----------------------------------  ------------------------------------------
  SokaMoNotificationPublisher.php     MO notifications: DB → RabbitMQ
  SokaPaymentNotificationPublisher.php Payment/billing callbacks → queue (tigo_soka_payment_router)
  BoomCustomerCreditAwardPublisher.php Promo/credit award events → queue
  SokaSmsReprocessPublisher.php       Failed/delayed SMS reprocess → queue
  SokaOpenQuestionScheduler.php       Cron-like scheduler for open questions
  SokaOpenQuestionPubliser.php        Note: filename typo "Publiser" in repo/check script
  SokaWebPaymentNotificationPublisher.php Web channel payment callbacks → queue
  SokaMpushYasSubsInitNotificationPublisher.php M-Push init → queue

CONSUMERS (typical prod counts from SokaConsumerCheck.sh):
  Script                              Workers   Role
  ----------------------------------  -------   ----------------------------------
  SokaPaymentConsumers.php            50        Payment callback processing (points, Q flow)
  SokaMoConsumer.php                  15        Inbound MO / gameplay
  BoomCustomerCreditAwardConsumer.php 10        Credit award handling
  SokaOpenQuestionConsumer.php        10        Open-question sessions
  SokaWebPaymentConsumers.php         5         Web payment channel
  SokaSmsReprocessConsumer.php        5         SMS retry/reprocess
  SokaMpushYasSubsInitNotificationConsumer.php 5  M-Push YAS activation

Soka payment queue (from SokaPaymentNotificationPublisher.php / SokaPaymentConsumers.php):
  Exchange: triviaExchange (direct)
  Queue:    tigo_soka_payment_router
  Key:      tigosoka.payments.key
  AMQP user (example): trivia_manage / tigo_manage on localhost:5672

Primary DB (DbConfig.php): trivia_tigotz_vas @ DB_HOST; credentials split publisher/consumer.
Renewal-related DB: tigo_renewals_db on RENEWAL_DB_HOST.

Tigo charging: GreenTelecom CP, OfferCode e.g. 017008600005, PAY/Payment endpoint (see SokaLib.php).

Other Soka PHP workers exist in repo (renewal, retry MO, activation) — not all are in Check.sh;
grep YAS-SOKA/*Consumer*.php and *Publisher*.php for full inventory.

6. ISHIKISTAA — processes (YAS-ISHIKISTAA-SDP/)
-----------------------------------------------
Monitored by IshikistaaNotificationPublisherCheck.sh + IshikistaaConsumerCheck.sh.

PUBLISHERS (expect 1 each):
  IshikistaaMoNotificationPublisher.php
  IshikistaaPaymentNotificationPublisher.php
  IshikistaaOpenQuestionPublisher.php
  IshikistaaOpenQuestionScheduler.php
  IshikistaaMpushYasSubsInitNotificationPublisher.php

CONSUMERS (typical prod counts from IshikistaaConsumerCheck.sh):
  IshikistaaPaymentConsumers.php        30
  IshikistaaMoConsumer.php              10
  IshikistaaOpenQuestionConsumer.php     5
  IshikistaaMpushYasSubsInitNotificationConsumer.php 5

Pattern mirrors Soka with IshikistaaLib / IshikistaaServices and ishikistaa_tigo_vas database.
Callback path: ishikistaa/notification → tbl_payment_ishikistaa_renewal_* / interaction_* tables.

7. MEGAPROMO / TESTI ZALI — processes (MEGAPROMO-APP/TIGO/)
-----------------------------------------------------------
Monitored by MegaPromoNotificationPublisherCheck.sh, MegaPromoMoConsumerCheck.sh,
MegaPromoPaymentCallbackConsumerCheck.sh, RenewalContentConsumerCheck.sh.

PUBLISHERS (expect 1 each):
  TigoMegaPromoMoPublisher.php
  TigoMegaPromoPaymentNotificationPublisher.php
  TigoMegaPromoTigPesaPushPublisher.php
  (Also in codebase: MegaPromoMpushYasSubsInitNotificationPublisher.php — wire like Soka M-Push)

CONSUMERS:
  Script                                    Workers   Role
  ----------------------------------------  -------   ---------------------------
  TigoMegaPromoMoconsumers.php              ≥20*      MO / box gameplay (*check uses pc=40, restart if <20)
  TigoMegaPromoPaymentCallbackConsumer.php  10        Payment callback processing
  TigoMegaPromoTigoPesaPushConsumer.php      5        TigoPesa push billing
  TigoMegaPromoRenewalContentConsumers.php   5        Renewal content notifications

RabbitMQ (app.config):
  EXCHANGE_NAME=megapromo_Exchange
  MO_QUEUE / MO_QUEUE_KEY
  PAYMENT_CALLBACK_QUEUE / PAYMENT_CALLBACK_KEY
  RENEWAL_CALLBACK_QUEUE / RENEWAL_CALLBACK_KEY
  RETRY_CALLBACK_QUEUE / RETRY_CALLBACK_KEY
  RENEWAL_CONTENT_QUEUE / RENEWAL_CONTENT_KEY
  TIGOPESA_PUSH_QUEUE / TIGOPESA_PUSH_KEY
  MPUSH_YAS_SUBS_INIT_QUEUE / MPUSH_YAS_SUBS_INIT_KEY
  QUEUE_USER=megapromo_manage

DB: tigo_megapromo (publisher), tigo_renewals_db for renewal tables (see db.config).

8. CROSS-CUTTING CONCERNS FOR GO REWRITE
----------------------------------------
Architecture
  - Split: (1) Callback ingress API, (2) Outbound Tigo client, (3) Per-product domain services,
    (4) Queue workers with explicit worker counts / autoscaling instead of bash loops.
  - Replace DB-polling publishers with: outbox table + relay, or publish directly from callback handler
    when safe; preserve at-least-once delivery and idempotent consumers.

Messages
  - JSON bodies on AMQP typically include: msisdn, txnstatus, paymentdetail, requestid, billingSystem
    (payment); MO messages add message, requesttype, referenceid, status, id.

Idempotency
  - Key on Tigo requestId / internal referenceId; payment callbacks may retry.

Config & secrets
  - Externalize DB, RabbitMQ, Tigo URLs, CP password, offer codes, SMS gateways (.env / vault).
  - Do not hardcode credentials from legacy DbConfig.php / app.config.

Observability
  - Legacy: file logs under logs/, hourly rotated names; Check.sh → /var/log/tigo_soka_monitor.log (Soka).
  - Go: structured logs, metrics (queue lag, consumer count, charge success rate), alerting.

Deployment
  - Today: nohup php Script.php & — multiple OS processes per script name.
  - Target: systemd units, supervisord, or K8s deployments with replicas matching former worker counts.

Data model
  - Daily sharded tables tbl_payment_*_YYYYMMDD — plan migration or views for Go ORM.
  - Separate trivia DB vs renewals DB vs megapromo DB — keep bounded contexts.

Testing
  - Use manual test scripts in repo (*ManualTest.php, test routes in YAS-CALLBACKS).
  - Contract tests from recorded callback JSON samples in logs.

9. SUGGESTED GO MODULE MAP
--------------------------
  cmd/callbacks/          HTTP server (replaces YAS-CALLBACKS controllers)
  cmd/soka-worker/        Consumer groups configurable by queue name
  cmd/ishikistaa-worker/
  cmd/megapromo-worker/
  internal/tigo/          Payment, ACT/Activate, token client
  internal/amqp/          Connection, publish, consume with QoS (QOS_LIMIT in legacy config)
  internal/store/         MySQL repositories matching *Services.php behavior
  internal/soka|ishikistaa|megapromo/  Domain: questions, points, prizes, SMS templates

10. REFERENCE — WHERE TO READ CODE FIRST
----------------------------------------
  YAS-CALLBACKS/application/controllers/SokaTrivia.php    Callback → table mapping
  YAS-SOKA/SokaPaymentConsumers.php                       Payment success/fail gameplay
  YAS-SOKA/SokaMoConsumer.php                             MO / answers / charging trigger
  YAS-SOKA/SokaLib.php                                    Tigo Payment + SMS
  YAS-ISHIKISTAA-SDP/IshikistaaPaymentConsumers.php       Ishikistaa parallel
  MEGAPROMO-APP/TIGO/TigoMegaPromoMoInteractionController.php  Zali MO logic
  MEGAPROMO-APP/TIGO/MegaPromoLib.php                     Charging / TigoPesa
  MEGAPROMO-APP/TIGO/app.config                            Queues, workers, SMS, billing URLs

11. OPS SCRIPTS (production paths differ; names match repo)
-----------------------------------------------------------
  YAS-SOKA/SokaPublisherCheck.sh, SokaConsumerCheck.sh
  YAS-ISHIKISTAA-SDP/IshikistaaNotificationPublisherCheck.sh, IshikistaaConsumerCheck.sh
  MEGAPROMO-APP/TIGO/MegaPromoNotificationPublisherCheck.sh
  MEGAPROMO-APP/TIGO/MegaPromoMoConsumerCheck.sh
  MEGAPROMO-APP/TIGO/MegaPromoPaymentCallbackConsumerCheck.sh
  MEGAPROMO-APP/TIGO/RenewalContentConsumerCheck.sh

When revamping, document equivalent process supervisor config and deprecate bash loops.

---
Document version: 2026-03-28 | Project: YAS-INTERACTIONS | Contact: Green Telecom VAS team
