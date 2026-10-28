-- New target database only. Soka Predict-and-Win from YAS-SOKA/PredictAndWin.php
-- (tbl_predict_campaign, _match_day, _fixtures, _session, _submissions).
-- Times are UTC; match dates are Dar es Salaam calendar dates.
CREATE TABLE predict_campaigns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id SMALLINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  status VARCHAR(20) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  legacy_id VARCHAR(191) NULL,
  PRIMARY KEY (id),
  KEY ix_predict_campaign_active (product_id, status, start_date, end_date),
  CONSTRAINT fk_predict_campaign_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT ck_predict_campaign_dates CHECK (end_date >= start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE predict_match_days (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  match_date DATE NOT NULL,
  status VARCHAR(20) NOT NULL,
  list_sms_text TEXT NULL,
  legacy_id VARCHAR(191) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_predict_match_day (campaign_id, match_date),
  CONSTRAINT fk_predict_match_day_campaign FOREIGN KEY (campaign_id) REFERENCES predict_campaigns(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE predict_fixtures (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  match_day_id BIGINT UNSIGNED NOT NULL,
  slot INT UNSIGNED NOT NULL,
  display_text VARCHAR(255) NOT NULL,
  kickoff_at DATETIME(6) NOT NULL,
  status VARCHAR(20) NOT NULL,
  legacy_id VARCHAR(191) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_predict_fixture_slot (match_day_id, slot),
  CONSTRAINT fk_predict_fixture_day FOREIGN KEY (match_day_id) REFERENCES predict_match_days(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE predict_submissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id SMALLINT UNSIGNED NOT NULL,
  subscriber_id BIGINT UNSIGNED NOT NULL,
  campaign_id BIGINT UNSIGNED NOT NULL,
  match_day_id BIGINT UNSIGNED NOT NULL,
  fixture_id BIGINT UNSIGNED NOT NULL,
  slot INT UNSIGNED NOT NULL,
  pred_home_goals INT UNSIGNED NOT NULL,
  pred_away_goals INT UNSIGNED NOT NULL,
  raw_text VARCHAR(32) NOT NULL,
  interaction_id BIGINT UNSIGNED NOT NULL,
  payment_attempt_id BIGINT UNSIGNED NULL,
  status VARCHAR(24) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  confirmed_at DATETIME(6) NULL,
  legacy_id VARCHAR(191) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_predict_submission_payment (payment_attempt_id),
  UNIQUE KEY uq_predict_submission_interaction (interaction_id),
  KEY ix_predict_submission_fixture (subscriber_id, fixture_id, status),
  CONSTRAINT fk_predict_submission_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_predict_submission_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers(id),
  CONSTRAINT fk_predict_submission_fixture FOREIGN KEY (fixture_id) REFERENCES predict_fixtures(id),
  CONSTRAINT fk_predict_submission_payment FOREIGN KEY (payment_attempt_id) REFERENCES payment_attempts(id),
  CONSTRAINT ck_predict_submission_status CHECK (status IN ('PENDING_PAYMENT','CONFIRMED','LOW_BALANCE_FAILED','PAYMENT_FAILED','WON','LOST'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One conversation state per subscriber, as tbl_predict_session keyed by MSISDN.
CREATE TABLE predict_sessions (
  product_id SMALLINT UNSIGNED NOT NULL,
  subscriber_id BIGINT UNSIGNED NOT NULL,
  campaign_id BIGINT UNSIGNED NOT NULL,
  match_day_id BIGINT UNSIGNED NOT NULL,
  max_slot INT UNSIGNED NOT NULL,
  state VARCHAR(20) NOT NULL,
  selected_slot INT UNSIGNED NULL,
  selected_fixture_id BIGINT UNSIGNED NULL,
  pending_submission_id BIGINT UNSIGNED NULL,
  expires_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (product_id, subscriber_id),
  CONSTRAINT fk_predict_session_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers(id),
  CONSTRAINT ck_predict_session_state CHECK (state IN ('LIST_SENT','FIXTURE_CHOSEN','BILLING_PENDING','SUBMITTED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
