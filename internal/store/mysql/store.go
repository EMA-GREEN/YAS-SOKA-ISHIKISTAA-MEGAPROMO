package mysqlstore

import (
	"bytes"
	"context"
	"database/sql"
	"errors"
	"fmt"
	"time"

	mysql "github.com/go-sql-driver/mysql"
	"yas-modernization/internal/callbacks"
)

type Store struct{ DB *sql.DB }

func Open(dsn string) (*Store, error) {
	if dsn == "" {
		return nil, errors.New("MYSQL_DSN is required")
	}
	cfg, err := mysql.ParseDSN(dsn)
	if err != nil {
		return nil, errors.New("invalid MYSQL_DSN")
	}
	if cfg.Params == nil {
		cfg.Params = make(map[string]string)
	}
	// Make DATETIME defaults and UTC_TIMESTAMP() comparable on every pooled connection.
	cfg.Params["time_zone"] = "'+00:00'"
	connector, err := mysql.NewConnector(cfg)
	if err != nil {
		return nil, err
	}
	db := sql.OpenDB(connector)
	db.SetMaxOpenConns(20)
	db.SetMaxIdleConns(10)
	db.SetConnMaxLifetime(30 * time.Minute)
	db.SetConnMaxIdleTime(5 * time.Minute)
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := db.PingContext(ctx); err != nil {
		db.Close()
		return nil, fmt.Errorf("database unavailable: %w", err)
	}
	return &Store{DB: db}, nil
}

func (s *Store) Ready(ctx context.Context) error { return s.DB.PingContext(ctx) }

// Save commits callback and outbox entry together. The unique key detects retries.
// A reused external ID with different content is surfaced rather than overwritten.
func (s *Store) Save(ctx context.Context, e callbacks.Event) (int64, error) {
	tx, err := s.DB.BeginTx(ctx, nil)
	if err != nil {
		return 0, err
	}
	defer tx.Rollback()
	result, err := tx.ExecContext(ctx, `INSERT INTO callback_events
        (product, external_request_id, event_kind, msisdn, payload_json, payload_sha256)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)`,
		e.Product, e.RequestID, e.Kind, e.MSISDN, e.Payload, e.Hash[:])
	if err != nil {
		return 0, err
	}
	id, err := result.LastInsertId()
	if err != nil {
		return 0, err
	}
	var storedHash []byte
	if err := tx.QueryRowContext(ctx, `SELECT payload_sha256 FROM callback_events WHERE id = ? FOR UPDATE`, id).Scan(&storedHash); err != nil {
		return 0, err
	}
	if !bytes.Equal(storedHash, e.Hash[:]) {
		return 0, callbacks.ErrConflict
	}
	_, err = tx.ExecContext(ctx, `INSERT INTO callback_outbox (event_id, product, event_kind, payload_json)
        VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id`, id, e.Product, e.Kind, e.Payload)
	if err != nil {
		return 0, err
	}
	if err := tx.Commit(); err != nil {
		return 0, err
	}
	return id, nil
}
