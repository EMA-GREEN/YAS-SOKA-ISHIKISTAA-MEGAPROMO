-- New target database only. Supports the Soka paid-answer flow from
-- YAS-SOKA/SokaMoConsumer.php and SokaPaymentConsumers.php.

-- LAST_QN_ID and USER_LEVEL in tbl_qn_subscribers.
ALTER TABLE product_subscriptions
  ADD COLUMN current_question_id BIGINT UNSIGNED NULL,
  ADD COLUMN answer_level INT UNSIGNED NOT NULL DEFAULT 0,
  ADD CONSTRAINT fk_subscription_question FOREIGN KEY (current_question_id, product_id) REFERENCES questions(id, product_id);

-- Points are decided when the answer arrives but only credited when its charge
-- succeeds, as in PHP's EARNED_POINTS with BILLING_STATUS='SENT_BILLING'.
ALTER TABLE answers
  ADD COLUMN points_on_success INT NOT NULL DEFAULT 0,
  ADD UNIQUE KEY uq_answer_interaction (interaction_id),
  ADD CONSTRAINT ck_answer_points CHECK (points_on_success >= 0),
  ADD CONSTRAINT ck_answer_result CHECK (result IN ('CORRECT','WRONG'));

-- A low-balance fallback charge replaces an earlier attempt for the same answer.
ALTER TABLE payment_attempts
  ADD COLUMN fallback_of_attempt_id BIGINT UNSIGNED NULL,
  ADD UNIQUE KEY uq_payment_fallback (fallback_of_attempt_id),
  ADD CONSTRAINT fk_payment_fallback FOREIGN KEY (fallback_of_attempt_id) REFERENCES payment_attempts(id);

-- tbl_qn_levels: a dated bonus for reaching a level, with its reward message.
CREATE TABLE answer_level_bonuses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id SMALLINT UNSIGNED NOT NULL,
  bonus_date DATE NOT NULL,
  level INT UNSIGNED NOT NULL,
  bonus_points INT NOT NULL,
  reward_message TEXT NULL,
  legacy_source VARCHAR(80) NULL,
  legacy_id VARCHAR(191) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_level_bonus (product_id, bonus_date, level),
  UNIQUE KEY uq_level_bonus_legacy (legacy_source, legacy_id),
  CONSTRAINT fk_level_bonus_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT ck_level_bonus_points CHECK (bonus_points >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
