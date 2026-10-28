-- New target database only. One delayed resend after an explicit provider
-- rejection, replacing sms_reprocess. delivery_state gains REJECTED for a
-- message the provider refused twice.
ALTER TABLE outbound_messages
  ADD COLUMN send_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN next_attempt_at DATETIME(6) NULL,
  ADD KEY ix_message_ready (delivery_state, next_attempt_at, id);
