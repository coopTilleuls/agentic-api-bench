package resource

import (
	"context"
	"net/http"
	"testing"

	"github.com/coopTilleuls/agentic-api-bench/internal/script"
)

func TestRuntimeTxKeepsEverythingInsideOneTransaction(t *testing.T) {
	pool := testPool(t)
	set, errs := checkFiles(t, pool, map[string]string{
		"Author.json":     authorJSON,
		"Book.json":       bookWritable,
		"book.query.ts":   bookWritableQuery,
		"book.command.ts": bookCommand,
	})
	if len(errs) != 0 {
		t.Fatal(errs.Error())
	}
	ctx := context.Background()
	tx, err := pool.Begin(ctx)
	if err != nil {
		t.Fatal(err)
	}
	defer tx.Rollback(ctx)
	rt := &Runtime{Pool: pool, Tx: tx, Set: set}
	req := script.Request{Header: http.Header{}}

	author := set.ByName("Author")
	id, err := rt.Write(ctx, author, "create", req, nil, map[string]any{"name": "Octavia Butler"}, nil)
	if err != nil || id != int64(3) {
		t.Fatalf("create: %v %v", id, err)
	}
	if _, total, err := rt.List(ctx, author, req, listExec(author, 1, nil)); err != nil || total != 3 {
		t.Fatalf("list inside the transaction: %d %v", total, err)
	}
	if has, err := rt.TableHas(ctx, author, int64(3)); err != nil || !has {
		t.Fatalf("TableHas: %v %v", has, err)
	}

	book := set.ByName("Book")
	bookID, err := rt.Write(ctx, book, "create", req, nil, map[string]any{"title": "Kindred", "author_id": int64(3)}, nil)
	if err != nil {
		t.Fatalf("a command reads the author written earlier in the transaction: %v", err)
	}
	if _, ok, err := rt.Get(ctx, book, req, bookID, "read", nil); err != nil || !ok {
		t.Fatalf("Get: %v %v", ok, err)
	}

	if err := tx.Rollback(ctx); err != nil {
		t.Fatal(err)
	}
	var n int
	if err := pool.QueryRow(ctx, "SELECT count(*) FROM author").Scan(&n); err != nil || n != 2 {
		t.Fatalf("author rows after rollback: %d %v", n, err)
	}
}
