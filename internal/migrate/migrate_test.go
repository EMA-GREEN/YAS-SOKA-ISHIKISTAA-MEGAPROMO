package migrate

import (
	"path/filepath"
	"strings"
	"testing"
)

func TestLoadCurrentMigrations(t *testing.T) {
	files, err := Load(filepath.Join("..", "..", "migrations"))
	if err != nil {
		t.Fatal(err)
	}
	if len(files) != 2 || files[0].Name != "001_callback_inbox_outbox.sql" || files[1].Name != "002_core_domain.sql" {
		t.Fatalf("unexpected migrations: %+v", files)
	}
	if len(files[0].Statements) < 2 || len(files[1].Statements) < 15 {
		t.Fatal("migration statements missing")
	}
}

func TestSplitSQLQuotedSemicolon(t *testing.T) {
	statements, err := SplitSQL("-- a comment\nINSERT INTO example VALUES ('a;b');\nSELECT 1;")
	if err != nil || len(statements) != 2 || !strings.Contains(statements[0], "a;b") {
		t.Fatalf("statements=%v err=%v", statements, err)
	}
	if _, err := SplitSQL("SELECT 'open;"); err == nil {
		t.Fatal("unterminated quote accepted")
	}
}
