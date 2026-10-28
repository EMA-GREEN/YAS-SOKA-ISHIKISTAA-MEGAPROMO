-- A submitted request may have reached the provider even when the caller times out.
-- SUBMITTING and NEEDS_REVIEW are never automatically retried.
ALTER TABLE charge_intents
  DROP CHECK ck_charge_state,
  ADD COLUMN request_started_at DATETIME(6) NULL,
  ADD COLUMN response_status INT NULL,
  ADD CONSTRAINT ck_charge_state CHECK (state IN ('PENDING','SUBMITTING','SUBMITTED','FAILED','NEEDS_REVIEW'));
