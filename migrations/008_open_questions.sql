-- New target database only. Soka open-question schedules from
-- openquestion_schedule: the legacy schedule carries its own text and an
-- audience instead of a question, so question_id becomes optional.
-- scheduled_for is UTC, like every other timestamp in the target schema.
ALTER TABLE question_schedules
  MODIFY question_id BIGINT UNSIGNED NULL,
  ADD COLUMN content TEXT NULL,
  ADD COLUMN audience VARCHAR(20) NOT NULL DEFAULT 'ALL',
  ADD COLUMN recipients INT UNSIGNED NULL,
  ADD CONSTRAINT ck_schedule_audience CHECK (audience IN ('PLAYING','TOP','ALL')),
  ADD CONSTRAINT ck_schedule_state CHECK (state IN ('PENDING','PROCESSING','PROCESSED','CANCELLED'));
