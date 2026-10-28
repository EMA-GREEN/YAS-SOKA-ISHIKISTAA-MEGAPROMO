-- Synthetic records only. Run against a disposable database after migrations 001 and 002.
SET time_zone = '+00:00';
INSERT INTO products (id, code, display_name) VALUES (1, 'soka', 'Soka'), (2, 'ishikistaa', 'Ishikistaa'), (3, 'megapromo', 'MegaPromo');
INSERT INTO subscribers (id, msisdn) VALUES (1, '255700000001');
INSERT INTO product_subscriptions (id, product_id, subscriber_id, state, started_at) VALUES (1, 1, 1, 'ACTIVE', '2026-10-28 12:00:00');
INSERT INTO questions (id, product_id, question_text, answer_key, points) VALUES (1, 1, 'Synthetic question?', 'A', 10);
INSERT INTO question_schedules (id, question_id, product_id, scheduled_for, state) VALUES (1, 1, 1, '2026-10-28 12:00:00', 'PUBLISHED');
INSERT INTO open_question_deliveries (id, schedule_id, subscription_id, product_id, subscriber_id, state, sent_at) VALUES (1, 1, 1, 1, 1, 'SENT', '2026-10-28 12:00:00');
INSERT INTO interactions (id, product_id, subscriber_id, external_request_id, interaction_kind, inbound_text, occurred_at)
  VALUES (1, 1, 1, 'test-mo-1', 'MO', 'A', '2026-10-28 12:00:00');
INSERT INTO payment_attempts (id, product_id, subscriber_id, interaction_id, external_request_id, payment_kind, channel, amount_minor, currency, state, requested_at)
  VALUES (1, 1, 1, 1, 'test-payment-1', 'PLAY', 'SMS', 300, 'TZS', 'SUCCESS', '2026-10-28 12:00:00');
INSERT INTO subscription_events (id, subscription_id, product_id, subscriber_id, event_kind, effective_at, payment_attempt_id, source_event_key)
  VALUES (1, 1, 1, 1, 'ACTIVATED', '2026-10-28 12:00:00', 1, 'test-sub-event-1');
INSERT INTO answers (id, interaction_id, question_id, payment_attempt_id, product_id, subscriber_id, answer_text, result, answered_at)
  VALUES (1, 1, 1, 1, 1, 1, 'A', 'CORRECT', '2026-10-28 12:00:00');
INSERT INTO point_ledger (id, product_id, subscriber_id, answer_id, payment_attempt_id, delta_points, reason, source_event_key, occurred_at)
  VALUES (1, 1, 1, 1, 1, 10, 'CORRECT_ANSWER', 'test-points-1', '2026-10-28 12:00:00');
INSERT INTO promo_entries (id, product_id, subscriber_id, payment_attempt_id, entry_count, awarded_at, source_event_key)
  VALUES (1, 1, 1, 1, 1, '2026-10-28 12:00:00', 'test-entry-1');
INSERT INTO prize_awards (id, product_id, subscriber_id, promo_entry_id, prize_reference, prize_kind, state, awarded_at)
  VALUES (1, 1, 1, 1, 'test-prize-1', 'TEST', 'PENDING', '2026-10-28 12:00:00');
INSERT INTO outbound_messages (id, product_id, subscriber_id, interaction_id, message_kind, body_text, delivery_state, source_event_key)
  VALUES (1, 1, 1, 1, 'QUESTION', 'Synthetic SMS', 'PENDING', 'test-message-1');
INSERT INTO callback_events (id, product, external_request_id, event_kind, msisdn, payload_json, payload_sha256)
  VALUES (1, 'soka', 'test-callback-1', 'Payment', '255700000001', '{"requestId":"test-callback-1"}', UNHEX(REPEAT('00', 32)));
INSERT INTO activation_attempts (id, subscription_id, product_id, subscriber_id, callback_event_id, external_request_id, channel, state, requested_at)
  VALUES (1, 1, 1, 1, 1, 'test-activation-1', 'MPUSH', 'CONFIRMED', '2026-10-28 12:00:00');
INSERT INTO outbound_message_events (id, outbound_message_id, event_kind, occurred_at)
  VALUES (1, 1, 'SENT', '2026-10-28 12:00:00');
INSERT INTO callback_outbox (id, event_id, product, event_kind, payload_json)
  VALUES (1, 1, 'soka', 'Payment', '{"requestId":"test-callback-1"}');
INSERT INTO payment_events (id, payment_attempt_id, callback_event_id, event_kind, occurred_at)
  VALUES (1, 1, 1, 'SUCCESS', '2026-10-28 12:00:00');
INSERT INTO processed_events (consumer_name, callback_event_id) VALUES ('test-consumer', 1);
SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()) AS table_count,
       (SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE()) AS foreign_key_count,
       (SELECT COALESCE(SUM(delta_points), 0) FROM point_ledger WHERE product_id = 1 AND subscriber_id = 1) AS point_balance;
