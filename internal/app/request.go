package app

import (
	"context"
	"net/http"
	"net/http/httptest"

	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/httpapi"
	"github.com/coopTilleuls/agentic-api-bench/internal/resource"
)

// Response is what the server answered to an in-process request.
type Response struct {
	Status int
	Header http.Header
	Body   []byte
}

// Request loads and checks dir exactly as the server does and answers req
// through the same handler, with no listener. The whole request runs in one
// transaction, rolled back unless commit is set. Definitions with errors are
// not served: the report is returned instead of a response.
func Request(ctx context.Context, dir string, pool *pgxpool.Pool, out httpapi.Output, req *http.Request, commit bool) (*Response, apierr.Report, error) {
	set, report := Validate(ctx, dir, pool, NewEngine())
	if !report.Valid {
		return nil, report, nil
	}
	tx, err := pool.Begin(ctx)
	if err != nil {
		return nil, report, apierr.FromPg(err, apierr.PgContext{})
	}
	defer func() { _ = tx.Rollback(context.Background()) }()
	srv := &httpapi.Server{Set: set, Runtime: &resource.Runtime{Pool: pool, Tx: tx, Set: set}, Global: globalErrors(set, report), Output: out}
	rec := httptest.NewRecorder()
	srv.ServeHTTP(rec, req.WithContext(ctx))
	if commit {
		if err := tx.Commit(ctx); err != nil {
			return nil, report, apierr.FromPg(err, apierr.PgContext{})
		}
	}
	res := rec.Result()
	return &Response{Status: res.StatusCode, Header: res.Header, Body: rec.Body.Bytes()}, report, nil
}
