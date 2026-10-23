package main

import (
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"strconv"
	"strings"
	"time"
)

type bookAuthor struct {
	ID   int     `json:"id"`
	Name *string `json:"name"`
}

type book struct {
	ID          int        `json:"id"`
	Title       string     `json:"title"`
	PriceCents  int        `json:"priceCents"`
	PublishedAt *string    `json:"publishedAt"`
	Stock       int        `json:"stock"`
	Author      bookAuthor `json:"author"`
}

const bookSelect = `SELECT b.id, b.title, b.price_cents, b.published_at, b.stock, a.id, a.name
FROM book b JOIN author a ON a.id = b.author_id`

var bookSortColumns = map[string]string{
	"priceCents":  "b.price_cents",
	"title":       "b.title",
	"publishedAt": "b.published_at",
}

type rowScanner interface {
	Scan(dest ...any) error
}

func scanBook(sc rowScanner) (book, error) {
	var b book
	var published sql.NullTime
	var authorName sql.NullString
	err := sc.Scan(&b.ID, &b.Title, &b.PriceCents, &published, &b.Stock, &b.Author.ID, &authorName)
	b.PublishedAt = nullDate(published)
	b.Author.Name = nullString(authorName)
	return b, err
}

// filter collects WHERE conditions with numbered placeholders.
type filter struct {
	conds []string
	args  []any
}

func (f *filter) add(cond string, v any) {
	f.args = append(f.args, v)
	f.conds = append(f.conds, fmt.Sprintf(cond, len(f.args)))
}

func (s *server) listBooks(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	p, err := parsePage(q)
	if err != nil {
		writeError(w, http.StatusBadRequest, "invalid pagination")
		return
	}

	f := filter{conds: []string{"b.published"}}
	if v, ok := q["title"]; ok {
		f.add("strpos(lower(b.title), lower($%d)) > 0", v[0])
	}
	if v, ok := q["author"]; ok {
		f.add("a.name = $%d", v[0])
	}
	for _, in := range []struct{ key, cond string }{
		{"priceCents[gte]", "b.price_cents >= $%d"},
		{"priceCents[lte]", "b.price_cents <= $%d"},
	} {
		if v, ok := q[in.key]; ok {
			n, err := strconv.Atoi(v[0])
			if err != nil {
				writeError(w, http.StatusBadRequest, in.key+" must be an integer")
				return
			}
			f.add(in.cond, n)
		}
	}
	for _, in := range []struct{ key, cond string }{
		{"publishedAt[after]", "b.published_at >= $%d::date"},
		{"publishedAt[before]", "b.published_at <= $%d::date"},
	} {
		if v, ok := q[in.key]; ok {
			t, err := time.Parse(time.DateOnly, v[0])
			if err != nil {
				writeError(w, http.StatusBadRequest, in.key+" must be a YYYY-MM-DD date")
				return
			}
			f.add(in.cond, t.Format(time.DateOnly))
		}
	}
	if v, ok := q["inStock"]; ok {
		switch v[0] {
		case "true":
			f.conds = append(f.conds, "b.stock > 0")
		case "false":
			f.conds = append(f.conds, "b.stock = 0")
		default:
			writeError(w, http.StatusBadRequest, "inStock must be true or false")
			return
		}
	}

	order, err := parseOrder(r.URL.RawQuery, bookSortColumns)
	if err != nil {
		writeError(w, http.StatusBadRequest, "invalid order")
		return
	}
	order = append(order, "b.id ASC")

	where := strings.Join(f.conds, " AND ")
	var total int
	err = s.db.QueryRow("SELECT count(*) FROM book b JOIN author a ON a.id = b.author_id WHERE "+where, f.args...).Scan(&total)
	if err != nil {
		serverError(w, err)
		return
	}

	items := []book{}
	if offset, ok := p.offset(total); ok {
		args := append(append([]any{}, f.args...), p.size, offset)
		n := len(f.args)
		query := fmt.Sprintf("%s WHERE %s ORDER BY %s LIMIT $%d OFFSET $%d",
			bookSelect, where, strings.Join(order, ", "), n+1, n+2)
		rows, err := s.db.Query(query, args...)
		if err != nil {
			serverError(w, err)
			return
		}
		for rows.Next() {
			b, err := scanBook(rows)
			if err != nil {
				rows.Close()
				serverError(w, err)
				return
			}
			items = append(items, b)
		}
		rows.Close()
		if err := rows.Err(); err != nil {
			serverError(w, err)
			return
		}
	}
	writeList(w, items, total, p)
}

func (s *server) getBook(w http.ResponseWriter, r *http.Request) {
	id, ok := parseID(r.PathValue("id"))
	if !ok {
		writeError(w, http.StatusNotFound, "book not found")
		return
	}
	b, err := scanBook(s.db.QueryRow(bookSelect+" WHERE b.id = $1 AND b.published", id))
	if errors.Is(err, sql.ErrNoRows) {
		writeError(w, http.StatusNotFound, "book not found")
		return
	}
	if err != nil {
		serverError(w, err)
		return
	}
	writeJSON(w, http.StatusOK, b)
}

func (s *server) patchBook(w http.ResponseWriter, r *http.Request) {
	id, ok := parseID(r.PathValue("id"))
	if !ok {
		writeError(w, http.StatusNotFound, "book not found")
		return
	}
	var body map[string]any
	dec := json.NewDecoder(r.Body)
	dec.UseNumber()
	if err := dec.Decode(&body); err != nil {
		writeError(w, http.StatusBadRequest, "invalid JSON body")
		return
	}

	var title *string
	var priceCents, stock *int
	if v, ok := body["title"]; ok {
		str, isString := v.(string)
		if !isString || str == "" {
			writeError(w, http.StatusUnprocessableEntity, "title must be a non-empty string")
			return
		}
		title = &str
	}
	if v, ok := body["priceCents"]; ok {
		n, err := intValue(v)
		if err != nil || n < 0 {
			writeError(w, http.StatusUnprocessableEntity, "priceCents must be an integer >= 0")
			return
		}
		priceCents = &n
	}
	if v, ok := body["stock"]; ok {
		n, err := intValue(v)
		if err != nil || n < 0 {
			writeError(w, http.StatusUnprocessableEntity, "stock must be an integer >= 0")
			return
		}
		stock = &n
	}

	var published bool
	err := s.db.QueryRow(`UPDATE book
		SET title = COALESCE($1, title), price_cents = COALESCE($2, price_cents), stock = COALESCE($3, stock)
		WHERE id = $4 RETURNING published`, title, priceCents, stock, id).Scan(&published)
	if errors.Is(err, sql.ErrNoRows) {
		writeError(w, http.StatusNotFound, "book not found")
		return
	}
	if err != nil {
		serverError(w, err)
		return
	}
	if !published {
		w.WriteHeader(http.StatusNoContent)
		return
	}
	b, err := scanBook(s.db.QueryRow(bookSelect+" WHERE b.id = $1", id))
	if err != nil {
		serverError(w, err)
		return
	}
	writeJSON(w, http.StatusOK, b)
}
