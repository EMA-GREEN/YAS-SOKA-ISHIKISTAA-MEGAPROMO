// Package testdb resets the isolated integration-test database. Integration
// tests in several packages share one yas_test_ database, so each must be able
// to clear rows another package left behind.
package testdb

import (
	"context"
	"database/sql"
	"fmt"
)

// Reset deletes all application rows, breaking self-references first and then
// deleting children before parents. It refuses to run outside a yas_test_
// database.
func Reset(ctx context.Context, db *sql.DB) error {
	var name string
	if err := db.QueryRowContext(ctx, "SELECT DATABASE()").Scan(&name); err != nil {
		return err
	}
	if len(name) < 9 || name[:9] != "yas_test_" {
		return fmt.Errorf("refusing to reset database %q", name)
	}
	for _, q := range []string{
		`DROP TRIGGER IF EXISTS fail_e2e_message`,
		`UPDATE product_subscriptions SET current_question_id=NULL`,
		`UPDATE payment_attempts SET fallback_of_attempt_id=NULL`,
		`DELETE FROM processed_events`,
		`DELETE FROM renewal_requests`,
		`DELETE FROM subscription_events`,
		`DELETE FROM outbound_message_events`,
		`DELETE FROM outbound_messages`,
		`DELETE FROM point_ledger`,
		`DELETE FROM payment_events`,
		`DELETE FROM predict_sessions`,
		`DELETE FROM predict_submissions`,
		`DELETE FROM charge_intents`,
		`DELETE FROM answers`,
		`DELETE FROM payment_attempts`,
		`DELETE FROM interactions`,
		`DELETE FROM open_question_deliveries`,
		`DELETE FROM question_schedules`,
		`DELETE FROM activation_attempts`,
		`DELETE FROM credit_awards`,
		`DELETE FROM product_subscriptions`,
		`DELETE FROM answer_level_bonuses`,
		`DELETE FROM questions`,
		`DELETE FROM predict_fixtures`,
		`DELETE FROM predict_match_days`,
		`DELETE FROM predict_campaigns`,
		`DELETE FROM callback_outbox`,
		`DELETE FROM callback_events`,
		`DELETE FROM products`,
		`DELETE FROM subscribers`,
	} {
		if _, err := db.ExecContext(ctx, q); err != nil {
			return fmt.Errorf("%s: %w", q, err)
		}
	}
	return nil
}
