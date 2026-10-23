package main

import (
	"encoding/csv"
	"fmt"
	"net/http"
	"strconv"
	"time"
)

func (s *server) salesReport(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	from, err := time.Parse(time.DateOnly, q.Get("from"))
	if err != nil {
		writeError(w, http.StatusBadRequest, "from must be a YYYY-MM-DD date")
		return
	}
	to, err := time.Parse(time.DateOnly, q.Get("to"))
	if err != nil {
		writeError(w, http.StatusBadRequest, "to must be a YYYY-MM-DD date")
		return
	}
	if to.Before(from) {
		writeError(w, http.StatusBadRequest, "from must not be after to")
		return
	}

	// Both bounds are whole UTC days: [from 00:00, to+1 day 00:00).
	rows, err := s.db.Query(`SELECT b.title, SUM(pl.quantity) AS quantity,
			SUM(pl.quantity * pl.unit_price_cents) AS revenue
		FROM purchase_line pl
		JOIN purchase p ON p.id = pl.purchase_id
		JOIN book b ON b.id = pl.book_id
		WHERE p.created_at >= $1 AND p.created_at < $2
		GROUP BY b.id, b.title
		ORDER BY revenue DESC, b.title ASC, b.id ASC`, from, to.AddDate(0, 0, 1))
	if err != nil {
		serverError(w, err)
		return
	}
	defer rows.Close()

	w.Header().Set("Content-Type", "text/csv; charset=utf-8")
	w.Header().Set("Content-Disposition", fmt.Sprintf(`attachment; filename="sales-%s-%s.csv"`, q.Get("from"), q.Get("to")))
	cw := csv.NewWriter(w)
	_ = cw.Write([]string{"title", "quantity", "revenueCents"})
	for rows.Next() {
		var title string
		var quantity, revenue int
		if err := rows.Scan(&title, &quantity, &revenue); err != nil {
			// Headers are already sent; the body is cut short rather than mixed with JSON.
			return
		}
		_ = cw.Write([]string{title, strconv.Itoa(quantity), strconv.Itoa(revenue)})
	}
	cw.Flush()
}
