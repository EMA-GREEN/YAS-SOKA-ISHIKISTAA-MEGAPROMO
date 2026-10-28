-- New target database only. Supports Soka subscription, unsubscription, and
-- free-credit play from YAS-SOKA/SokaMoConsumer.php.

-- tbl_qn_subscribers.CREDIT, BOOM_CREDIT, and DND_STATUS.
ALTER TABLE product_subscriptions
  ADD COLUMN free_credits INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN boom_credits INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN do_not_disturb BOOLEAN NOT NULL DEFAULT FALSE;

-- sendRenewal(): PHP posts every subscribe and unsubscribe to two renewal
-- endpoints. Each endpoint gets its own row so one failing does not hide the
-- other. Uncertain sends are reviewed, never retried automatically.
CREATE TABLE renewal_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id SMALLINT UNSIGNED NOT NULL,
  subscriber_id BIGINT UNSIGNED NOT NULL,
  source_callback_event_id BIGINT UNSIGNED NOT NULL,
  activity_type VARCHAR(20) NOT NULL,
  target VARCHAR(40) NOT NULL,
  state VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  response_status INT NULL,
  last_error VARCHAR(255) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  request_started_at DATETIME(6) NULL,
  sent_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_renewal_request (source_callback_event_id, target),
  KEY ix_renewal_ready (state, id),
  CONSTRAINT fk_renewal_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_renewal_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers(id),
  CONSTRAINT fk_renewal_source FOREIGN KEY (source_callback_event_id) REFERENCES callback_events(id),
  CONSTRAINT ck_renewal_activity CHECK (activity_type IN ('SUBSCRIPTION','UNSUBSCRIPTION')),
  CONSTRAINT ck_renewal_state CHECK (state IN ('PENDING','SENDING','SENT','NEEDS_REVIEW'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
