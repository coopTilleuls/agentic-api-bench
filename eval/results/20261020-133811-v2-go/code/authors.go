package main

import (
	"database/sql"
	"errors"
	"net/http"
)

type authorSummary struct {
	ID             int     `json:"id"`
	Name           *string `json:"name"`
	PublishedBooks int     `json:"publishedBooks"`
}

type bookRef struct {
	ID    int    `json:"id"`
	Title string `json:"title"`
}

type authorDetail struct {
	ID    int       `json:"id"`
	Name  *string   `json:"name"`
	Books []bookRef `json:"books"`
}

func (s *server) listAuthors(w http.ResponseWriter, r *http.Request) {
	p, err := parsePage(r.URL.Query())
	if err != nil {
		writeError(w, http.StatusBadRequest, "invalid pagination")
		return
	}
	var total int
	if err := s.db.QueryRow("SELECT count(*) FROM author").Scan(&total); err != nil {
		serverError(w, err)
		return
	}

	items := []authorSummary{}
	if offset, ok := p.offset(total); ok {
		rows, err := s.db.Query(`SELECT a.id, a.name,
			(SELECT count(*) FROM book b WHERE b.author_id = a.id AND b.published)
			FROM author a ORDER BY a.id LIMIT $1 OFFSET $2`, p.size, offset)
		if err != nil {
			serverError(w, err)
			return
		}
		for rows.Next() {
			var a authorSummary
			var name sql.NullString
			if err := rows.Scan(&a.ID, &name, &a.PublishedBooks); err != nil {
				rows.Close()
				serverError(w, err)
				return
			}
			a.Name = nullString(name)
			items = append(items, a)
		}
		rows.Close()
		if err := rows.Err(); err != nil {
			serverError(w, err)
			return
		}
	}
	writeList(w, items, total, p)
}

func (s *server) getAuthor(w http.ResponseWriter, r *http.Request) {
	id, ok := parseID(r.PathValue("id"))
	if !ok {
		writeError(w, http.StatusNotFound, "author not found")
		return
	}
	var a authorDetail
	var name sql.NullString
	err := s.db.QueryRow("SELECT id, name FROM author WHERE id = $1", id).Scan(&a.ID, &name)
	if errors.Is(err, sql.ErrNoRows) {
		writeError(w, http.StatusNotFound, "author not found")
		return
	}
	if err != nil {
		serverError(w, err)
		return
	}
	a.Name = nullString(name)

	rows, err := s.db.Query("SELECT id, title FROM book WHERE author_id = $1 AND published ORDER BY id", id)
	if err != nil {
		serverError(w, err)
		return
	}
	defer rows.Close()
	a.Books = []bookRef{}
	for rows.Next() {
		var b bookRef
		if err := rows.Scan(&b.ID, &b.Title); err != nil {
			serverError(w, err)
			return
		}
		a.Books = append(a.Books, b)
	}
	if err := rows.Err(); err != nil {
		serverError(w, err)
		return
	}
	writeJSON(w, http.StatusOK, a)
}
