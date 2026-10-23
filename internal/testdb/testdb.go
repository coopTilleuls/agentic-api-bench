// Package testdb gives integration tests an isolated schema in the database
// named by LLMAPI_TEST_DSN, and skips them when it is unset.
package testdb

import (
	"context"
	"fmt"
	"math/rand/v2"
	"os"
	"path/filepath"
	"runtime"
	"testing"

	"github.com/jackc/pgx/v5/pgxpool"
)

const Fixture = `
CREATE TABLE author (id serial PRIMARY KEY, name text NOT NULL);
CREATE TABLE book (id serial PRIMARY KEY, title text NOT NULL, author_id int REFERENCES author(id), published boolean NOT NULL, tenant text NOT NULL DEFAULT 'acme', price numeric(6,2), released_at timestamptz);
INSERT INTO author (name) VALUES ('Ursula K. Le Guin'), ('Frank Herbert');
INSERT INTO book (title, author_id, published, price, released_at) VALUES
 ('A Wizard of Earthsea', 1, true, 9.90, '1968-11-01T00:00:00Z'),
 ('The Left Hand of Darkness', 1, true, 12.50, '1969-03-01T00:00:00Z'),
 ('Unpublished Draft', 1, false, NULL, NULL),
 ('Dune', 2, true, 15.00, '1965-08-01T00:00:00Z');
`

func Pool(t *testing.T, fixture string) *pgxpool.Pool {
	t.Helper()
	dsn := os.Getenv("LLMAPI_TEST_DSN")
	if dsn == "" {
		t.Skip("LLMAPI_TEST_DSN not set")
	}
	ctx := context.Background()
	schema := fmt.Sprintf("t_%d", rand.Int64())
	admin, err := pgxpool.New(ctx, dsn)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := admin.Exec(ctx, "CREATE SCHEMA "+schema); err != nil {
		t.Fatal(err)
	}
	cfg, err := pgxpool.ParseConfig(dsn)
	if err != nil {
		t.Fatal(err)
	}
	cfg.ConnConfig.RuntimeParams["search_path"] = schema
	pool, err := pgxpool.NewWithConfig(ctx, cfg)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := pool.Exec(ctx, fixture); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		pool.Close()
		_, _ = admin.Exec(context.Background(), "DROP SCHEMA "+schema+" CASCADE")
		admin.Close()
	})
	return pool
}

// Shop loads eval/v2/seed.sql, the bookshop of the v2 spec.
func Shop(t *testing.T) *pgxpool.Pool {
	t.Helper()
	_, file, _, _ := runtime.Caller(0)
	seed, err := os.ReadFile(filepath.Join(filepath.Dir(file), "..", "..", "eval", "v2", "seed.sql"))
	if err != nil {
		t.Fatal(err)
	}
	return Pool(t, string(seed))
}
