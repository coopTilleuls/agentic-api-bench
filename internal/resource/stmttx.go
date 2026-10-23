package resource

import (
	"context"
	"fmt"
	"sync/atomic"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgconn"
)

// stmtTx runs every statement in its own savepoint, so a failed statement
// leaves the transaction usable, as it is when each statement autocommits.
type stmtTx struct {
	pgx.Tx
}

var savepoints atomic.Uint64

func (s *stmtTx) savepoint(ctx context.Context) (string, error) {
	name := fmt.Sprintf("stmt_%d", savepoints.Add(1))
	_, err := s.Tx.Exec(ctx, "SAVEPOINT "+name)
	return name, err
}

func (s *stmtTx) finish(ctx context.Context, name string, failed bool) {
	if failed {
		_, _ = s.Tx.Exec(ctx, "ROLLBACK TO SAVEPOINT "+name)
	}
	_, _ = s.Tx.Exec(ctx, "RELEASE SAVEPOINT "+name)
}

func (s *stmtTx) Begin(ctx context.Context) (pgx.Tx, error) {
	tx, err := s.Tx.Begin(ctx)
	if err != nil {
		return nil, err
	}
	return &stmtTx{Tx: tx}, nil
}

func (s *stmtTx) Exec(ctx context.Context, sql string, args ...any) (pgconn.CommandTag, error) {
	name, err := s.savepoint(ctx)
	if err != nil {
		return pgconn.CommandTag{}, err
	}
	tag, err := s.Tx.Exec(ctx, sql, args...)
	s.finish(ctx, name, err != nil)
	return tag, err
}

func (s *stmtTx) Query(ctx context.Context, sql string, args ...any) (pgx.Rows, error) {
	name, err := s.savepoint(ctx)
	if err != nil {
		return nil, err
	}
	rows, err := s.Tx.Query(ctx, sql, args...)
	if err != nil {
		s.finish(ctx, name, true)
		return nil, err
	}
	return &stmtRows{Rows: rows, tx: s, ctx: ctx, name: name}, nil
}

func (s *stmtTx) QueryRow(ctx context.Context, sql string, args ...any) pgx.Row {
	rows, err := s.Query(ctx, sql, args...)
	return &stmtRow{rows: rows, err: err}
}

type stmtRows struct {
	pgx.Rows
	tx   *stmtTx
	ctx  context.Context
	name string
	done bool
}

func (r *stmtRows) Close() {
	if r.done {
		return
	}
	r.Rows.Close()
	r.done = true
	r.tx.finish(r.ctx, r.name, r.Rows.Err() != nil)
}

type stmtRow struct {
	rows pgx.Rows
	err  error
}

func (r *stmtRow) Scan(dest ...any) error {
	if r.err != nil {
		return r.err
	}
	defer r.rows.Close()
	if !r.rows.Next() {
		if err := r.rows.Err(); err != nil {
			return err
		}
		return pgx.ErrNoRows
	}
	return r.rows.Scan(dest...)
}
