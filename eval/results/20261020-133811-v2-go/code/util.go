package main

import (
	"database/sql"
	"encoding/json"
	"errors"
	"log"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"
)

var errInvalid = errors.New("invalid parameter")

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

func writeError(w http.ResponseWriter, status int, msg string) {
	writeJSON(w, status, map[string]string{"error": msg})
}

func serverError(w http.ResponseWriter, err error) {
	log.Print(err)
	writeError(w, http.StatusInternalServerError, "internal error")
}

// parseID returns false for anything that is not a positive integer,
// so malformed ids answer 404 like unknown ones.
func parseID(s string) (int, bool) {
	id, err := strconv.Atoi(s)
	return id, err == nil && id > 0
}

type page struct {
	number int
	size   int
}

// parsePage reads page and itemsPerPage, defaulting to 1 and 10.
func parsePage(q url.Values) (page, error) {
	p := page{number: 1, size: 10}
	if v, ok := q["page"]; ok {
		n, err := strconv.Atoi(v[0])
		if err != nil || n < 1 {
			return p, errInvalid
		}
		p.number = n
	}
	if v, ok := q["itemsPerPage"]; ok {
		n, err := strconv.Atoi(v[0])
		if err != nil || n < 1 || n > 50 {
			return p, errInvalid
		}
		p.size = n
	}
	return p, nil
}

// offset returns the SQL offset of the page. It returns false when the page
// lies beyond the last one, in which case the items are empty.
func (p page) offset(total int) (int, bool) {
	if p.number-1 >= total {
		return 0, false
	}
	return (p.number - 1) * p.size, true
}

type listResponse struct {
	Items        any `json:"items"`
	Total        int `json:"total"`
	Page         int `json:"page"`
	ItemsPerPage int `json:"itemsPerPage"`
}

func writeList(w http.ResponseWriter, items any, total int, p page) {
	writeJSON(w, http.StatusOK, listResponse{Items: items, Total: total, Page: p.number, ItemsPerPage: p.size})
}

// parseOrder reads the order[field]=asc|desc parameters in the order they
// appear in the raw query, since their sequence sets the sort priority.
func parseOrder(rawQuery string, columns map[string]string) ([]string, error) {
	var clauses []string
	seen := map[string]bool{}
	for _, pair := range strings.Split(rawQuery, "&") {
		key, value, _ := strings.Cut(pair, "=")
		key, err := url.QueryUnescape(key)
		if err != nil {
			return nil, errInvalid
		}
		if !strings.HasPrefix(key, "order[") || !strings.HasSuffix(key, "]") {
			continue
		}
		field := key[len("order[") : len(key)-1]
		column, ok := columns[field]
		if !ok {
			return nil, errInvalid
		}
		value, err = url.QueryUnescape(value)
		if err != nil || (value != "asc" && value != "desc") {
			return nil, errInvalid
		}
		if seen[field] {
			continue
		}
		seen[field] = true
		clauses = append(clauses, column+" "+strings.ToUpper(value))
	}
	return clauses, nil
}

// intValue accepts only JSON integers, rejecting strings, booleans and fractions.
func intValue(v any) (int, error) {
	n, ok := v.(json.Number)
	if !ok {
		return 0, errInvalid
	}
	i, err := n.Int64()
	if err != nil {
		return 0, errInvalid
	}
	return int(i), nil
}

func nullString(s sql.NullString) *string {
	if !s.Valid {
		return nil
	}
	return &s.String
}

func nullDate(t sql.NullTime) *string {
	if !t.Valid {
		return nil
	}
	s := t.Time.Format(time.DateOnly)
	return &s
}
