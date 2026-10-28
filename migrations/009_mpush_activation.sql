-- New target database only. M-Push YAS subscription-init activation requests
-- (tbl_mpush_subs_init_activation_logs_YYYYMMDD). Activation may start
-- subscription billing, so uncertain sends are reviewed, never retried.
ALTER TABLE activation_attempts
  ADD COLUMN ussd_data VARCHAR(255) NULL,
  ADD COLUMN request_started_at DATETIME(6) NULL,
  ADD COLUMN response_status INT NULL,
  ADD COLUMN provider_status VARCHAR(80) NULL,
  ADD COLUMN last_error VARCHAR(255) NULL,
  ADD KEY ix_activation_ready (state, id),
  ADD CONSTRAINT ck_activation_state CHECK (state IN ('PENDING','SENDING','SENT','NEEDS_REVIEW','CONFIRMED'));
