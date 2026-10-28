-- DESIGN DRAFT ONLY. Not an executable migration against the existing databases.
-- MySQL 8.0+ syntax; validate engine/version, collation, source columns, and volumes first.
CREATE TABLE products (
  id SMALLINT UNSIGNED PRIMARY KEY,
  code VARCHAR(24) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE subscribers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  msisdn VARCHAR(32) NOT NULL UNIQUE,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;

CREATE TABLE product_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id SMALLINT UNSIGNED NOT NULL,
  subscriber_id BIGINT UNSIGNED NOT NULL,
  state VARCHAR(32) NOT NULL,
  started_at DATETIME(6) NULL,
  expires_at DATETIME(6) NULL,
  ended_at DATETIME(6) NULL,
  legacy_source VARCHAR(64) NULL,
  legacy_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_product_subscriber (product_id, subscriber_id),
  UNIQUE KEY uq_legacy_subscription (legacy_source, legacy_id),
  KEY ix_subscription_expiry (state, expires_at),
  CONSTRAINT fk_subscription_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_subscription_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers(id)
) ENGINE=InnoDB;

CREATE TABLE questions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id SMALLINT UNSIGNED NOT NULL,
  question_text TEXT NOT NULL,
  answer_key VARCHAR(255) NULL,
  points INT NOT NULL DEFAULT 0,
  valid_from DATETIME(6) NULL,
  valid_until DATETIME(6) NULL,
  legacy_source VARCHAR(64) NULL,
  legacy_id BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_legacy_question (legacy_source, legacy_id),
  KEY ix_question_product_validity (product_id, valid_from, valid_until),
  CONSTRAINT fk_question_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE interactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id SMALLINT UNSIGNED NOT NULL,
  subscriber_id BIGINT UNSIGNED NOT NULL,
  external_request_id VARCHAR(191) NULL,
  interaction_kind VARCHAR(40) NOT NULL,
  occurred_at DATETIME(6) NOT NULL,
  legacy_source VARCHAR(64) NULL,
  legacy_id BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_legacy_interaction (legacy_source, legacy_id),
  KEY ix_interaction_subscriber_time (product_id, subscriber_id, occurred_at, id),
  KEY ix_interaction_external (product_id, external_request_id),
  CONSTRAINT fk_interaction_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_interaction_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers(id)
) ENGINE=InnoDB;

CREATE TABLE payment_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  interaction_id BIGINT UNSIGNED NULL,
  product_id SMALLINT UNSIGNED NOT NULL,
  subscriber_id BIGINT UNSIGNED NOT NULL,
  external_request_id VARCHAR(191) NOT NULL,
  provider_transaction_id VARCHAR(191) NULL,
  channel VARCHAR(32) NOT NULL,
  amount_minor BIGINT NOT NULL,
  currency CHAR(3) NOT NULL,
  state VARCHAR(32) NOT NULL,
  requested_at DATETIME(6) NOT NULL,
  settled_at DATETIME(6) NULL,
  legacy_source VARCHAR(64) NULL,
  legacy_id BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_payment_request (product_id, external_request_id),
  UNIQUE KEY uq_legacy_payment (legacy_source, legacy_id),
  KEY ix_payment_subscriber_time (product_id, subscriber_id, requested_at, id),
  KEY ix_payment_state_time (state, requested_at),
  CONSTRAINT fk_payment_interaction FOREIGN KEY (interaction_id) REFERENCES interactions(id),
  CONSTRAINT fk_payment_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_payment_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers(id)
) ENGINE=InnoDB;

CREATE TABLE answers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  interaction_id BIGINT UNSIGNED NOT NULL,
  question_id BIGINT UNSIGNED NOT NULL,
  payment_attempt_id BIGINT UNSIGNED NULL,
  answer_text TEXT NOT NULL,
  result VARCHAR(32) NOT NULL,
  points_awarded INT NOT NULL DEFAULT 0,
  answered_at DATETIME(6) NOT NULL,
  legacy_source VARCHAR(64) NULL,
  legacy_id BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_legacy_answer (legacy_source, legacy_id),
  KEY ix_answer_question_time (question_id, answered_at),
  CONSTRAINT fk_answer_interaction FOREIGN KEY (interaction_id) REFERENCES interactions(id),
  CONSTRAINT fk_answer_question FOREIGN KEY (question_id) REFERENCES questions(id),
  CONSTRAINT fk_answer_payment FOREIGN KEY (payment_attempt_id) REFERENCES payment_attempts(id)
) ENGINE=InnoDB;

CREATE TABLE promo_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id SMALLINT UNSIGNED NOT NULL,
  subscriber_id BIGINT UNSIGNED NOT NULL,
  payment_attempt_id BIGINT UNSIGNED NULL,
  entry_count INT UNSIGNED NOT NULL,
  awarded_at DATETIME(6) NOT NULL,
  legacy_source VARCHAR(64) NULL,
  legacy_id BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_legacy_entry (legacy_source, legacy_id),
  KEY ix_entry_product_time (product_id, awarded_at),
  CONSTRAINT fk_entry_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_entry_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers(id),
  CONSTRAINT fk_entry_payment FOREIGN KEY (payment_attempt_id) REFERENCES payment_attempts(id)
) ENGINE=InnoDB;

CREATE TABLE outbound_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id SMALLINT UNSIGNED NOT NULL,
  subscriber_id BIGINT UNSIGNED NOT NULL,
  interaction_id BIGINT UNSIGNED NULL,
  message_kind VARCHAR(40) NOT NULL,
  delivery_state VARCHAR(32) NOT NULL,
  provider_reference VARCHAR(191) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  sent_at DATETIME(6) NULL,
  legacy_source VARCHAR(64) NULL,
  legacy_id BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_legacy_message (legacy_source, legacy_id),
  KEY ix_message_state_time (delivery_state, created_at),
  CONSTRAINT fk_message_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_message_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers(id),
  CONSTRAINT fk_message_interaction FOREIGN KEY (interaction_id) REFERENCES interactions(id)
) ENGINE=InnoDB;
