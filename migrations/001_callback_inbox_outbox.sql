-- Proposed additive schema for MySQL 8.0+. Review against production version before applying.
-- No existing table is modified. Run only after backup/restore rehearsal and approval.
CREATE TABLE callback_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product VARCHAR(24) NOT NULL,
  external_request_id VARCHAR(191) NOT NULL,
  event_kind VARCHAR(64) NOT NULL,
  msisdn VARCHAR(32) NOT NULL,
  payload_json JSON NOT NULL,
  payload_sha256 BINARY(32) NOT NULL,
  received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_callback_identity (product, external_request_id, event_kind),
  KEY ix_callback_received (received_at, id),
  KEY ix_callback_msisdn (product, msisdn, received_at)
) ENGINE=InnoDB;

CREATE TABLE callback_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NOT NULL,
  product VARCHAR(24) NOT NULL,
  event_kind VARCHAR(64) NOT NULL,
  payload_json JSON NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  locked_until DATETIME(6) NULL,
  lease_token BINARY(16) NULL,
  published_at DATETIME(6) NULL,
  dead_lettered_at DATETIME(6) NULL,
  last_error VARCHAR(255) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_outbox_event (event_id),
  KEY ix_outbox_ready (published_at, dead_lettered_at, next_attempt_at, locked_until, id),
  CONSTRAINT fk_outbox_event FOREIGN KEY (event_id) REFERENCES callback_events(id)
) ENGINE=InnoDB;
