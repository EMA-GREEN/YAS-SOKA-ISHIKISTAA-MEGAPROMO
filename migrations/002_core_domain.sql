-- Target-domain schema for a NEW, isolated MySQL 8.0+ database.
-- Additive only. Do not run against production or point existing PHP at it.
-- Historical source-to-target mapping requires the live source schema.

CREATE TABLE products (
  id SMALLINT UNSIGNED NOT NULL,
  code VARCHAR(24) NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE subscribers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  msisdn VARCHAR(32) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscribers_msisdn (msisdn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE product_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id SMALLINT UNSIGNED NOT NULL,
  subscriber_id BIGINT UNSIGNED NOT NULL,
  state VARCHAR(32) NOT NULL,
  started_at DATETIME(6) NULL,
  expires_at DATETIME(6) NULL,
  ended_at DATETIME(6) NULL,
  legacy_source VARCHAR(80) NULL,
  legacy_id VARCHAR(191) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscription_identity (id, product_id, subscriber_id),
  UNIQUE KEY uq_subscription_current (product_id, subscriber_id),
  UNIQUE KEY uq_subscription_legacy (legacy_source, legacy_id),
  KEY ix_subscription_expiry (state, expires_at, id),
  KEY ix_subscription_subscriber (subscriber_id, id),
  CONSTRAINT fk_subscription_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_subscription_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE subscription_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subscription_id BIGINT UNSIGNED NOT NULL,
  product_id SMALLINT UNSIGNED NOT NULL,
  subscriber_id BIGINT UNSIGNED NOT NULL,
  event_kind VARCHAR(40) NOT NULL,
  effective_at DATETIME(6) NOT NULL,
  payment_attempt_id BIGINT UNSIGNED NULL,
  source_event_key VARCHAR(191) NULL,
  legacy_source VARCHAR(80) NULL,
  legacy_id VARCHAR(191) NULL,
  recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_subscription_event_source (subscription_id, source_event_key),
  UNIQUE KEY uq_subscription_event_legacy (legacy_source, legacy_id),
  KEY ix_subscription_event_time (subscription_id, effective_at, id),
  KEY ix_subscription_event_identity (subscription_id, product_id, subscriber_id),
  KEY ix_subscription_event_payment (payment_attempt_id, product_id, subscriber_id),
  CONSTRAINT fk_subscription_event_subscription FOREIGN KEY (subscription_id, product_id, subscriber_id) REFERENCES product_subscriptions(id, product_id, subscriber_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE questions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id SMALLINT UNSIGNED NOT NULL,
  question_text TEXT NOT NULL,
  answer_key VARCHAR(255) NULL,
  points INT NOT NULL DEFAULT 0,
  valid_from DATETIME(6) NULL,
  valid_until DATETIME(6) NULL,
  legacy_source VARCHAR(80) NULL,
  legacy_id VARCHAR(191) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_question_identity (id, product_id),
  UNIQUE KEY uq_question_legacy (legacy_source, legacy_id),
  KEY ix_question_product_validity (product_id, valid_from, valid_until),
  CONSTRAINT fk_question_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT ck_question_validity CHECK (valid_until IS NULL OR valid_from IS NULL OR valid_until >= valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE question_schedules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_id BIGINT UNSIGNED NOT NULL,
  product_id SMALLINT UNSIGNED NOT NULL,
  scheduled_for DATETIME(6) NOT NULL,
  state VARCHAR(32) NOT NULL,
  published_at DATETIME(6) NULL,
  legacy_source VARCHAR(80) NULL,
  legacy_id VARCHAR(191) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_schedule_identity (id, product_id),
  UNIQUE KEY uq_schedule_legacy (legacy_source, legacy_id),
  KEY ix_schedule_due (state, scheduled_for, id),
  KEY ix_schedule_question (question_id, product_id),
  CONSTRAINT fk_schedule_question FOREIGN KEY (question_id, product_id) REFERENCES questions(id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE open_question_deliveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  schedule_id BIGINT UNSIGNED NOT NULL,
  subscription_id BIGINT UNSIGNED NOT NULL,
  product_id SMALLINT UNSIGNED NOT NULL,
  subscriber_id BIGINT UNSIGNED NOT NULL,
  state VARCHAR(32) NOT NULL,
  sent_at DATETIME(6) NULL,
  answered_at DATETIME(6) NULL,
  legacy_source VARCHAR(80) NULL,
  legacy_id VARCHAR(191) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_open_delivery (schedule_id, subscription_id),
  UNIQUE KEY uq_open_delivery_legacy (legacy_source, legacy_id),
  KEY ix_open_delivery_schedule (schedule_id, product_id),
  KEY ix_open_delivery_subscriber (subscription_id, product_id, subscriber_id),
  CONSTRAINT fk_open_delivery_schedule FOREIGN KEY (schedule_id, product_id) REFERENCES question_schedules(id, product_id),
  CONSTRAINT fk_open_delivery_subscription FOREIGN KEY (subscription_id, product_id, subscriber_id) REFERENCES product_subscriptions(id, product_id, subscriber_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE activation_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subscription_id BIGINT UNSIGNED NOT NULL,
  product_id SMALLINT UNSIGNED NOT NULL,
  subscriber_id BIGINT UNSIGNED NOT NULL,
  callback_event_id BIGINT UNSIGNED NULL,
  external_request_id VARCHAR(191) NOT NULL,
  channel VARCHAR(32) NOT NULL,
  state VARCHAR(32) NOT NULL,
  requested_at DATETIME(6) NOT NULL,
  responded_at DATETIME(6) NULL,
  legacy_source VARCHAR(80) NULL,
  legacy_id VARCHAR(191) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_activation_request (subscription_id, external_request_id),
  UNIQUE KEY uq_activation_callback (callback_event_id),
  UNIQUE KEY uq_activation_legacy (legacy_source, legacy_id),
  KEY ix_activation_subscription (subscription_id, product_id, subscriber_id),
  CONSTRAINT fk_activation_subscription FOREIGN KEY (subscription_id, product_id, subscriber_id) REFERENCES product_subscriptions(id, product_id, subscriber_id),
  CONSTRAINT fk_activation_callback FOREIGN KEY (callback_event_id) REFERENCES callback_events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE interactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id SMALLINT UNSIGNED NOT NULL,
  subscriber_id BIGINT UNSIGNED NOT NULL,
  external_request_id VARCHAR(191) NULL,
  interaction_kind VARCHAR(40) NOT NULL,
  inbound_text TEXT NULL,
  occurred_at DATETIME(6) NOT NULL,
  legacy_source VARCHAR(80) NULL,
  legacy_id VARCHAR(191) NULL,
  recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_interaction_identity (id, product_id, subscriber_id),
  UNIQUE KEY uq_interaction_legacy (legacy_source, legacy_id),
  KEY ix_interaction_subscriber_time (product_id, subscriber_id, occurred_at, id),
  KEY ix_interaction_external (product_id, external_request_id),
  KEY ix_interaction_subscriber_fk (subscriber_id),
  CONSTRAINT fk_interaction_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_interaction_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE payment_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id SMALLINT UNSIGNED NOT NULL,
  subscriber_id BIGINT UNSIGNED NOT NULL,
  interaction_id BIGINT UNSIGNED NULL,
  external_request_id VARCHAR(191) NOT NULL,
  provider_transaction_id VARCHAR(191) NULL,
  payment_kind VARCHAR(40) NOT NULL,
  channel VARCHAR(32) NOT NULL,
  amount_minor BIGINT NOT NULL,
  currency CHAR(3) NOT NULL,
  state VARCHAR(32) NOT NULL,
  requested_at DATETIME(6) NOT NULL,
  settled_at DATETIME(6) NULL,
  legacy_source VARCHAR(80) NULL,
  legacy_id VARCHAR(191) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_identity (id, product_id, subscriber_id),
  UNIQUE KEY uq_payment_request (product_id, external_request_id),
  UNIQUE KEY uq_payment_legacy (legacy_source, legacy_id),
  KEY ix_payment_subscriber_time (product_id, subscriber_id, requested_at, id),
  KEY ix_payment_state_time (state, requested_at, id),
  KEY ix_payment_interaction (interaction_id, product_id, subscriber_id),
  KEY ix_payment_subscriber_fk (subscriber_id),
  CONSTRAINT fk_payment_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_payment_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers(id),
  CONSTRAINT fk_payment_interaction FOREIGN KEY (interaction_id, product_id, subscriber_id) REFERENCES interactions(id, product_id, subscriber_id),
  CONSTRAINT ck_payment_amount CHECK (amount_minor >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE subscription_events
  ADD CONSTRAINT fk_subscription_event_payment FOREIGN KEY (payment_attempt_id, product_id, subscriber_id) REFERENCES payment_attempts(id, product_id, subscriber_id);

CREATE TABLE payment_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_attempt_id BIGINT UNSIGNED NOT NULL,
  callback_event_id BIGINT UNSIGNED NULL,
  event_kind VARCHAR(40) NOT NULL,
  provider_status VARCHAR(80) NULL,
  occurred_at DATETIME(6) NOT NULL,
  legacy_source VARCHAR(80) NULL,
  legacy_id VARCHAR(191) NULL,
  recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_event_callback (callback_event_id),
  UNIQUE KEY uq_payment_event_legacy (legacy_source, legacy_id),
  KEY ix_payment_event_time (payment_attempt_id, occurred_at, id),
  CONSTRAINT fk_payment_event_attempt FOREIGN KEY (payment_attempt_id) REFERENCES payment_attempts(id),
  CONSTRAINT fk_payment_event_callback FOREIGN KEY (callback_event_id) REFERENCES callback_events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE answers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  interaction_id BIGINT UNSIGNED NOT NULL,
  question_id BIGINT UNSIGNED NOT NULL,
  payment_attempt_id BIGINT UNSIGNED NULL,
  product_id SMALLINT UNSIGNED NOT NULL,
  subscriber_id BIGINT UNSIGNED NOT NULL,
  answer_text TEXT NOT NULL,
  result VARCHAR(32) NOT NULL,
  answered_at DATETIME(6) NOT NULL,
  legacy_source VARCHAR(80) NULL,
  legacy_id VARCHAR(191) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_answer_identity (id, product_id, subscriber_id),
  UNIQUE KEY uq_answer_legacy (legacy_source, legacy_id),
  KEY ix_answer_question_time (question_id, product_id, answered_at),
  KEY ix_answer_payment (payment_attempt_id, product_id, subscriber_id),
  KEY ix_answer_interaction (interaction_id, product_id, subscriber_id),
  CONSTRAINT fk_answer_interaction FOREIGN KEY (interaction_id, product_id, subscriber_id) REFERENCES interactions(id, product_id, subscriber_id),
  CONSTRAINT fk_answer_question FOREIGN KEY (question_id, product_id) REFERENCES questions(id, product_id),
  CONSTRAINT fk_answer_payment FOREIGN KEY (payment_attempt_id, product_id, subscriber_id) REFERENCES payment_attempts(id, product_id, subscriber_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE point_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id SMALLINT UNSIGNED NOT NULL,
  subscriber_id BIGINT UNSIGNED NOT NULL,
  answer_id BIGINT UNSIGNED NULL,
  payment_attempt_id BIGINT UNSIGNED NULL,
  delta_points BIGINT NOT NULL,
  reason VARCHAR(40) NOT NULL,
  source_event_key VARCHAR(191) NOT NULL,
  occurred_at DATETIME(6) NOT NULL,
  legacy_source VARCHAR(80) NULL,
  legacy_id VARCHAR(191) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_points_event (product_id, source_event_key),
  UNIQUE KEY uq_points_legacy (legacy_source, legacy_id),
  KEY ix_points_balance (product_id, subscriber_id, id),
  KEY ix_points_answer (answer_id, product_id, subscriber_id),
  KEY ix_points_payment (payment_attempt_id, product_id, subscriber_id),
  KEY ix_points_subscriber_fk (subscriber_id),
  CONSTRAINT fk_points_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_points_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers(id),
  CONSTRAINT fk_points_answer FOREIGN KEY (answer_id, product_id, subscriber_id) REFERENCES answers(id, product_id, subscriber_id),
  CONSTRAINT fk_points_payment FOREIGN KEY (payment_attempt_id, product_id, subscriber_id) REFERENCES payment_attempts(id, product_id, subscriber_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE promo_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id SMALLINT UNSIGNED NOT NULL,
  subscriber_id BIGINT UNSIGNED NOT NULL,
  payment_attempt_id BIGINT UNSIGNED NULL,
  entry_count INT UNSIGNED NOT NULL,
  awarded_at DATETIME(6) NOT NULL,
  source_event_key VARCHAR(191) NULL,
  legacy_source VARCHAR(80) NULL,
  legacy_id VARCHAR(191) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_entry_identity (id, product_id, subscriber_id),
  UNIQUE KEY uq_entry_event (product_id, source_event_key),
  UNIQUE KEY uq_entry_legacy (legacy_source, legacy_id),
  KEY ix_entry_product_time (product_id, awarded_at, id),
  KEY ix_entry_subscriber (subscriber_id, awarded_at),
  KEY ix_entry_payment (payment_attempt_id, product_id, subscriber_id),
  CONSTRAINT fk_entry_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_entry_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers(id),
  CONSTRAINT fk_entry_payment FOREIGN KEY (payment_attempt_id, product_id, subscriber_id) REFERENCES payment_attempts(id, product_id, subscriber_id),
  CONSTRAINT ck_entry_count CHECK (entry_count > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE prize_awards (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id SMALLINT UNSIGNED NOT NULL,
  subscriber_id BIGINT UNSIGNED NOT NULL,
  promo_entry_id BIGINT UNSIGNED NULL,
  prize_reference VARCHAR(191) NOT NULL,
  prize_kind VARCHAR(40) NOT NULL,
  amount_minor BIGINT NULL,
  currency CHAR(3) NULL,
  state VARCHAR(32) NOT NULL,
  awarded_at DATETIME(6) NOT NULL,
  fulfilled_at DATETIME(6) NULL,
  legacy_source VARCHAR(80) NULL,
  legacy_id VARCHAR(191) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_prize_reference (product_id, prize_reference),
  UNIQUE KEY uq_prize_legacy (legacy_source, legacy_id),
  KEY ix_prize_state_time (state, awarded_at, id),
  KEY ix_prize_subscriber (subscriber_id, awarded_at),
  KEY ix_prize_entry (promo_entry_id, product_id, subscriber_id),
  CONSTRAINT fk_prize_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_prize_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers(id),
  CONSTRAINT fk_prize_entry FOREIGN KEY (promo_entry_id, product_id, subscriber_id) REFERENCES promo_entries(id, product_id, subscriber_id),
  CONSTRAINT ck_prize_amount CHECK (amount_minor IS NULL OR amount_minor >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE outbound_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id SMALLINT UNSIGNED NOT NULL,
  subscriber_id BIGINT UNSIGNED NOT NULL,
  interaction_id BIGINT UNSIGNED NULL,
  message_kind VARCHAR(40) NOT NULL,
  body_text TEXT NOT NULL,
  delivery_state VARCHAR(32) NOT NULL,
  provider_reference VARCHAR(191) NULL,
  source_event_key VARCHAR(191) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  sent_at DATETIME(6) NULL,
  legacy_source VARCHAR(80) NULL,
  legacy_id VARCHAR(191) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_message_event (product_id, source_event_key),
  UNIQUE KEY uq_message_legacy (legacy_source, legacy_id),
  KEY ix_message_state_time (delivery_state, created_at, id),
  KEY ix_message_subscriber_time (subscriber_id, created_at, id),
  KEY ix_message_interaction (interaction_id, product_id, subscriber_id),
  CONSTRAINT fk_message_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_message_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers(id),
  CONSTRAINT fk_message_interaction FOREIGN KEY (interaction_id, product_id, subscriber_id) REFERENCES interactions(id, product_id, subscriber_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE outbound_message_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  outbound_message_id BIGINT UNSIGNED NOT NULL,
  event_kind VARCHAR(40) NOT NULL,
  provider_status VARCHAR(80) NULL,
  source_event_key VARCHAR(191) NULL,
  occurred_at DATETIME(6) NOT NULL,
  legacy_source VARCHAR(80) NULL,
  legacy_id VARCHAR(191) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_message_event_source (outbound_message_id, source_event_key),
  UNIQUE KEY uq_message_event_legacy (legacy_source, legacy_id),
  KEY ix_message_event_time (outbound_message_id, occurred_at, id),
  CONSTRAINT fk_message_event_message FOREIGN KEY (outbound_message_id) REFERENCES outbound_messages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE processed_events (
  consumer_name VARCHAR(80) NOT NULL,
  callback_event_id BIGINT UNSIGNED NOT NULL,
  processed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (consumer_name, callback_event_id),
  KEY ix_processed_event (callback_event_id),
  CONSTRAINT fk_processed_callback FOREIGN KEY (callback_event_id) REFERENCES callback_events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
