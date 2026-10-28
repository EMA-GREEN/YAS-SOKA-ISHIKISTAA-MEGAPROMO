-- New target database only. One row per subscription per award round, so
-- re-running a round cannot grant a credit the customer has already used.
-- Replaces tbl_boom_customer_base and its IS_SUBMITTED flag.
CREATE TABLE credit_awards (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id SMALLINT UNSIGNED NOT NULL,
  subscription_id BIGINT UNSIGNED NOT NULL,
  award_kind VARCHAR(20) NOT NULL,
  award_round VARCHAR(40) NOT NULL,
  awarded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_credit_award (subscription_id, award_kind, award_round),
  KEY ix_credit_award_round (product_id, award_kind, award_round),
  CONSTRAINT fk_credit_award_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_credit_award_subscription FOREIGN KEY (subscription_id) REFERENCES product_subscriptions(id),
  CONSTRAINT ck_credit_award_kind CHECK (award_kind IN ('BOOM'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
