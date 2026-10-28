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
	want := []string{"001_callback_inbox_outbox.sql", "002_core_domain.sql", "003_charge_intents.sql", "004_charge_dispatch.sql", "005_soka_answers.sql", "006_soka_subscriptions.sql", "007_credit_awards.sql", "008_open_questions.sql", "009_mpush_activation.sql", "010_predict_and_win.sql"}
	if len(files) != len(want) {
		t.Fatalf("got %d migrations, want %d", len(files), len(want))
	}
	for i, name := range want {
		if files[i].Name != name {
			t.Fatalf("migration %d is %s, want %s", i, files[i].Name, name)
		}
	}
	if len(files[0].Statements) < 2 || len(files[1].Statements) < 15 || len(files[2].Statements) != 2 || len(files[3].Statements) != 1 || len(files[4].Statements) != 4 || len(files[5].Statements) != 2 {
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
