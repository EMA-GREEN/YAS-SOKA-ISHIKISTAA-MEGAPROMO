package migrate

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"slices"
	"strings"
)

var filenamePattern = regexp.MustCompile(`^[0-9]{3}_[a-z0-9_]+\.sql$`)

type File struct {
	Name       string
	Hash       [32]byte
	Statements []string
}

func Load(dir string) ([]File, error) {
	entries, err := os.ReadDir(dir)
	if err != nil {
		return nil, err
	}
	var names []string
	for _, entry := range entries {
		if !entry.IsDir() && filenamePattern.MatchString(entry.Name()) {
			names = append(names, entry.Name())
		}
	}
	slices.Sort(names)
	if len(names) == 0 {
		return nil, errors.New("no numbered SQL migrations found")
	}
	files := make([]File, 0, len(names))
	for _, name := range names {
		body, err := os.ReadFile(filepath.Join(dir, name))
		if err != nil {
			return nil, err
		}
		statements, err := SplitSQL(string(body))
		if err != nil {
			return nil, fmt.Errorf("%s: %w", name, err)
		}
		if len(statements) == 0 {
			return nil, fmt.Errorf("%s: empty migration", name)
		}
		files = append(files, File{Name: name, Hash: sha256.Sum256(body), Statements: statements})
	}
	return files, nil
}

// SplitSQL supports the DDL files in this repository. Stored routines and
// custom DELIMITER directives require a different parser and are rejected.
func SplitSQL(script string) ([]string, error) {
	var cleaned strings.Builder
	for _, line := range strings.Split(script, "\n") {
		trimmed := strings.TrimSpace(line)
		if strings.HasPrefix(trimmed, "--") || strings.HasPrefix(trimmed, "#") {
			continue
		}
		if strings.HasPrefix(strings.ToUpper(trimmed), "DELIMITER ") {
			return nil, errors.New("custom delimiter is unsupported")
		}
		cleaned.WriteString(line)
		cleaned.WriteByte('\n')
	}
	var result []string
	var statement strings.Builder
	var quote byte
	data := cleaned.String()
	for i := 0; i < len(data); i++ {
		c := data[i]
		if quote != 0 {
			statement.WriteByte(c)
			if c == '\\' && i+1 < len(data) {
				i++
				statement.WriteByte(data[i])
				continue
			}
			if c == quote {
				if i+1 < len(data) && data[i+1] == quote {
					i++
					statement.WriteByte(data[i])
					continue
				}
				quote = 0
			}
			continue
		}
		switch c {
		case '\'', '"', '`':
			quote = c
			statement.WriteByte(c)
		case ';':
			if s := strings.TrimSpace(statement.String()); s != "" {
				result = append(result, s)
			}
			statement.Reset()
		default:
			statement.WriteByte(c)
		}
	}
	if quote != 0 {
		return nil, errors.New("unterminated quoted SQL")
	}
	if s := strings.TrimSpace(statement.String()); s != "" {
		return nil, errors.New("SQL statement missing final semicolon")
	}
	return result, nil
}

// Apply records a migration as started before executing DDL. MySQL DDL is not
// transactional; a failed migration stays blocked for manual inspection.
func Apply(ctx context.Context, db *sql.DB, files []File) error {
	conn, err := db.Conn(ctx)
	if err != nil {
		return err
	}
	defer conn.Close()
	var locked int
	if err := conn.QueryRowContext(ctx, `SELECT GET_LOCK('yas_modernization_schema', 0)`).Scan(&locked); err != nil {
		return err
	}
	if locked != 1 {
		return errors.New("another migration process holds the schema lock")
	}
	defer conn.ExecContext(context.Background(), `SELECT RELEASE_LOCK('yas_modernization_schema')`)
	if _, err := conn.ExecContext(ctx, `CREATE TABLE IF NOT EXISTS schema_migrations (
        filename VARCHAR(255) NOT NULL PRIMARY KEY,
        sha256 BINARY(32) NOT NULL,
        status VARCHAR(16) NOT NULL,
        started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        applied_at DATETIME(6) NULL,
        failed_statement INT NULL
    ) ENGINE=InnoDB`); err != nil {
		return err
	}
	for _, file := range files {
		var previousHash []byte
		var status string
		err := conn.QueryRowContext(ctx, `SELECT sha256, status FROM schema_migrations WHERE filename = ?`, file.Name).Scan(&previousHash, &status)
		if err != nil && !errors.Is(err, sql.ErrNoRows) {
			return err
		}
		if err == nil {
			if !slices.Equal(previousHash, file.Hash[:]) {
				return fmt.Errorf("migration checksum changed: %s", file.Name)
			}
			if status != "applied" {
				return fmt.Errorf("migration needs manual repair: %s (%s)", file.Name, status)
			}
			continue
		}
		if _, err := conn.ExecContext(ctx, `INSERT INTO schema_migrations (filename, sha256, status) VALUES (?, ?, 'started')`, file.Name, file.Hash[:]); err != nil {
			return err
		}
		for index, statement := range file.Statements {
			if _, err := conn.ExecContext(ctx, statement); err != nil {
				_, _ = conn.ExecContext(context.Background(), `UPDATE schema_migrations SET status = 'failed', failed_statement = ? WHERE filename = ?`, index+1, file.Name)
				return fmt.Errorf("%s statement %d failed: %w", file.Name, index+1, err)
			}
		}
		if _, err := conn.ExecContext(ctx, `UPDATE schema_migrations SET status = 'applied', applied_at = UTC_TIMESTAMP(6) WHERE filename = ?`, file.Name); err != nil {
			return err
		}
	}
	return nil
}
