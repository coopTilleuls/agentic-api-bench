package resource

import (
	"bytes"
	"context"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"slices"
	"strings"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgconn"

	"github.com/coopTilleuls/agentic-api-bench/internal/apierr"
	"github.com/coopTilleuls/agentic-api-bench/internal/script"
	"github.com/coopTilleuls/agentic-api-bench/internal/sqlf"
)

type querier interface {
	Query(ctx context.Context, sql string, args ...any) (pgx.Rows, error)
	Exec(ctx context.Context, sql string, args ...any) (pgconn.CommandTag, error)
}

const maxFetchBody = 10 << 20

var defaultClient = &http.Client{Timeout: 5 * time.Second}

type host struct {
	rt      *Runtime
	q       querier
	d       *Definition
	pointer string
}

func (r *Runtime) host(q querier, d *Definition, pointer string) script.Host {
	return &host{rt: r, q: q, d: d, pointer: pointer}
}

func (h *host) pgErr(err error, f sqlf.Fragment) *apierr.Error {
	sql, _ := f.Render(0)
	return apierr.FromPg(err, apierr.PgContext{Resource: h.d.Name, Pointer: h.pointer, SQL: sql, Body: h.pointer == "/command"})
}

func (h *host) Query(ctx context.Context, f sqlf.Fragment) ([]map[string]any, error) {
	sql, args := f.Render(0)
	rows, err := h.q.Query(ctx, sql, args...)
	if err != nil {
		return nil, h.pgErr(err, f)
	}
	defer rows.Close()
	fields := rows.FieldDescriptions()
	var out []map[string]any
	for rows.Next() {
		vals, err := rows.Values()
		if err != nil {
			return nil, h.pgErr(err, f)
		}
		row := make(map[string]any, len(vals))
		for i, fd := range fields {
			row[fd.Name] = toJSON("", vals[i])
		}
		out = append(out, row)
	}
	if err := rows.Err(); err != nil {
		return nil, h.pgErr(err, f)
	}
	return out, nil
}

func (h *host) Exec(ctx context.Context, f sqlf.Fragment) (int64, error) {
	sql, args := f.Render(0)
	tag, err := h.q.Exec(ctx, sql, args...)
	if err != nil {
		return 0, h.pgErr(err, f)
	}
	return tag.RowsAffected(), nil
}

func (h *host) Fetch(ctx context.Context, r script.FetchRequest) (*script.FetchResponse, error) {
	u, err := url.Parse(r.URL)
	if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
		return nil, &apierr.Error{Code: apierr.FetchFailed, Resource: h.d.Name, Pointer: h.pointer, Message: fmt.Sprintf("ctx.fetch: %q is not an absolute http(s) URL", r.URL), Hint: "Pass a full URL such as https://api.example.com/items."}
	}
	if !slices.Contains(h.d.Allow.Fetch, u.Host) && !slices.Contains(h.d.Allow.Fetch, u.Hostname()) {
		return nil, &apierr.Error{Code: apierr.FetchDenied, Resource: h.d.Name, Pointer: "/allow/fetch", Message: fmt.Sprintf("ctx.fetch to host %q is not allowed", u.Host), Hint: fmt.Sprintf("Add %q to \"allow\": {\"fetch\": [...]} in %s.", u.Host, h.d.File)}
	}
	var body io.Reader
	if r.Body != "" {
		body = strings.NewReader(r.Body)
	}
	req, err := http.NewRequestWithContext(ctx, r.Method, r.URL, body)
	if err != nil {
		return nil, &apierr.Error{Code: apierr.FetchFailed, Resource: h.d.Name, Pointer: h.pointer, Message: "ctx.fetch: " + err.Error(), Hint: "Check the method and URL."}
	}
	for k, v := range r.Headers {
		req.Header.Set(k, v)
	}
	client := h.rt.Client
	if client == nil {
		client = defaultClient
	}
	resp, err := client.Do(req)
	if err != nil {
		return nil, &apierr.Error{Code: apierr.FetchFailed, Resource: h.d.Name, Pointer: h.pointer, Message: fmt.Sprintf("ctx.fetch %s %s: %v", r.Method, r.URL, err), Hint: "Check that the remote service is running and the URL is right."}
	}
	defer resp.Body.Close()
	var buf bytes.Buffer
	if _, err := io.Copy(&buf, io.LimitReader(resp.Body, maxFetchBody)); err != nil {
		return nil, &apierr.Error{Code: apierr.FetchFailed, Resource: h.d.Name, Pointer: h.pointer, Message: fmt.Sprintf("ctx.fetch %s %s: reading body: %v", r.Method, r.URL, err)}
	}
	headers := map[string]string{}
	for k := range resp.Header {
		headers[strings.ToLower(k)] = resp.Header.Get(k)
	}
	return &script.FetchResponse{Status: resp.StatusCode, Headers: headers, Body: buf.Bytes()}, nil
}

func (h *host) Env(name string) (string, error) {
	if !slices.Contains(h.d.Allow.Env, name) {
		return "", &apierr.Error{Code: apierr.EnvDenied, Resource: h.d.Name, Pointer: "/allow/env", Message: fmt.Sprintf("ctx.env(%q) is not allowed", name), Hint: fmt.Sprintf("Add %q to \"allow\": {\"env\": [...]} in %s.", name, h.d.File)}
	}
	getenv := h.rt.Getenv
	if getenv == nil {
		getenv = os.Getenv
	}
	return getenv(name), nil
}
