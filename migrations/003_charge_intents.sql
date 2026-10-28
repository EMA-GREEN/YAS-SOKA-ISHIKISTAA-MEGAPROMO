-- New target database only. Billing segment must be verified before dispatch.
ALTER TABLE product_subscriptions
  ADD COLUMN billing_segment VARCHAR(20) NOT NULL DEFAULT 'UNVERIFIED',
  ADD CONSTRAINT ck_subscription_billing_segment CHECK (billing_segment IN ('UNVERIFIED','NORMAL','BOOM'));

CREATE TABLE charge_intents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_attempt_id BIGINT UNSIGNED NOT NULL,
  source_callback_event_id BIGINT UNSIGNED NOT NULL,
  offer_code VARCHAR(32) NOT NULL,
  reference_id VARCHAR(191) NULL,
  state VARCHAR(32) NOT NULL DEFAULT 'PENDING',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  locked_until DATETIME(6) NULL,
  lease_token BINARY(16) NULL,
  submitted_at DATETIME(6) NULL,
  last_error VARCHAR(255) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_charge_payment (payment_attempt_id),
  UNIQUE KEY uq_charge_source (source_callback_event_id),
  KEY ix_charge_ready (state, next_attempt_at, locked_until, id),
  CONSTRAINT fk_charge_payment FOREIGN KEY (payment_attempt_id) REFERENCES payment_attempts(id),
  CONSTRAINT fk_charge_source FOREIGN KEY (source_callback_event_id) REFERENCES callback_events(id),
  CONSTRAINT ck_charge_state CHECK (state IN ('PENDING','SUBMITTED','FAILED','NEEDS_REVIEW'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
