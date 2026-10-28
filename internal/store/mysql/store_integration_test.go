package mysqlstore

import (
	"context"
	"crypto/sha256"
	"errors"
	"os"
	"strings"
	"testing"

	mysqldriver "github.com/go-sql-driver/mysql"
	"yas-modernization/internal/callbacks"
	"yas-modernization/internal/testdb"
)

// This test deliberately mutates schema. It only runs against an explicitly named test DB.
func TestSaveIntegration(t *testing.T) {
	dsn := os.Getenv("TEST_MYSQL_DSN")
	if dsn == "" {
		t.Skip("set TEST_MYSQL_DSN for isolated MySQL integration test")
	}
	cfg, err := mysqldriver.ParseDSN(dsn)
	if err != nil || !strings.HasPrefix(cfg.DBName, "yas_test_") {
		t.Fatal("TEST_MYSQL_DSN must select a yas_test_ database")
	}
	s, err := Open(dsn)
	if err != nil {
		t.Fatal(err)
	}
	defer s.DB.Close()
	ctx := context.Background()
	var dbName string
	if err := s.DB.QueryRowContext(ctx, "SELECT DATABASE()").Scan(&dbName); err != nil || dbName != cfg.DBName {
		t.Fatal("connected database differs from TEST_MYSQL_DSN")
	}
	// The database is disposable and has received the numbered migrations.
	if err := testdb.Reset(ctx, s.DB); err != nil {
		t.Fatal(err)
	}
	event := func(request, payload string) callbacks.Event {
		e := callbacks.Event{Product: "soka", RequestID: request, Kind: "payment", MSISDN: "255700000001", Payload: []byte(payload)}
		e.Hash = sha256.Sum256(e.Payload)
		return e
	}
	first := event("integration-1", `{"requestId":"integration-1"}`)
	id, err := s.Save(ctx, first)
	if err != nil || id == 0 {
		t.Fatalf("initial save id=%d err=%v", id, err)
	}
	retryID, err := s.Save(ctx, first)
	if err != nil || retryID != id {
		t.Fatalf("retry id=%d err=%v; want id=%d", retryID, err, id)
	}
	if _, err := s.Save(ctx, event("integration-1", `{"requestId":"integration-1","changed":true}`)); !errors.Is(err, callbacks.ErrConflict) {
		t.Fatalf("changed retry error=%v; want conflict", err)
	}
	var inboxCount, outboxCount int
	if err := s.DB.QueryRowContext(ctx, "SELECT COUNT(*) FROM callback_events").Scan(&inboxCount); err != nil {
		t.Fatal(err)
	}
	if err := s.DB.QueryRowContext(ctx, "SELECT COUNT(*) FROM callback_outbox").Scan(&outboxCount); err != nil {
		t.Fatal(err)
	}
	if inboxCount != 1 || outboxCount != 1 {
		t.Fatalf("after retry/conflict inbox=%d outbox=%d; want 1 each", inboxCount, outboxCount)
	}
	if _, err := s.DB.ExecContext(ctx, `CREATE TRIGGER fail_test_outbox BEFORE INSERT ON callback_outbox FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected outbox failure'`); err != nil {
		t.Fatal(err)
	}
	defer func() {
		if _, err := s.DB.ExecContext(ctx, "DROP TRIGGER fail_test_outbox"); err != nil {
			t.Errorf("drop test trigger: %v", err)
		}
	}()
	if _, err := s.Save(ctx, event("integration-rollback", `{"requestId":"integration-rollback"}`)); err == nil {
		t.Fatal("expected injected outbox failure")
	}
	if err := s.DB.QueryRowContext(ctx, "SELECT COUNT(*) FROM callback_events WHERE external_request_id='integration-rollback'").Scan(&inboxCount); err != nil {
		t.Fatal(err)
	}
	if inboxCount != 0 {
		t.Fatalf("failed outbox insert left %d inbox rows; want 0", inboxCount)
	}
}
