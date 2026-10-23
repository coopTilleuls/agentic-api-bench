package main

import (
	"bytes"
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"slices"
	"strconv"
	"strings"
	"time"
)

const paymentURL = "http://127.0.0.1:18090/payments"

var paymentClient = &http.Client{Timeout: 5 * time.Second}

var errDeclined = errors.New("payment declined")

type purchaseLine struct {
	BookID         int    `json:"bookId"`
	Title          string `json:"title"`
	Quantity       int    `json:"quantity"`
	UnitPriceCents int    `json:"unitPriceCents"`
}

type purchase struct {
	ID         int            `json:"id"`
	CreatedAt  string         `json:"createdAt"`
	TotalCents int            `json:"totalCents"`
	Lines      []purchaseLine `json:"lines"`
}

// requireCustomer checks the Customer-Id header before the handler runs.
func (s *server) requireCustomer(next func(http.ResponseWriter, *http.Request, int)) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		id, err := strconv.Atoi(strings.TrimSpace(r.Header.Get("Customer-Id")))
		if err != nil || id < 1 {
			writeError(w, http.StatusUnauthorized, "missing or invalid Customer-Id header")
			return
		}
		var exists bool
		if err := s.db.QueryRow("SELECT EXISTS (SELECT 1 FROM customer WHERE id = $1)", id).Scan(&exists); err != nil {
			serverError(w, err)
			return
		}
		if !exists {
			writeError(w, http.StatusUnauthorized, "unknown customer")
			return
		}
		next(w, r, id)
	}
}

func scanPurchase(sc rowScanner) (purchase, error) {
	var p purchase
	var createdAt time.Time
	err := sc.Scan(&p.ID, &createdAt, &p.TotalCents)
	p.CreatedAt = createdAt.UTC().Format(time.RFC3339)
	p.Lines = []purchaseLine{}
	return p, err
}

func (s *server) loadLines(p *purchase) error {
	rows, err := s.db.Query(`SELECT pl.book_id, b.title, pl.quantity, pl.unit_price_cents
		FROM purchase_line pl JOIN book b ON b.id = pl.book_id
		WHERE pl.purchase_id = $1 ORDER BY pl.id`, p.ID)
	if err != nil {
		return err
	}
	defer rows.Close()
	for rows.Next() {
		var l purchaseLine
		if err := rows.Scan(&l.BookID, &l.Title, &l.Quantity, &l.UnitPriceCents); err != nil {
			return err
		}
		p.Lines = append(p.Lines, l)
	}
	return rows.Err()
}

func (s *server) listPurchases(w http.ResponseWriter, r *http.Request, customerID int) {
	pg, err := parsePage(r.URL.Query())
	if err != nil {
		writeError(w, http.StatusBadRequest, "invalid pagination")
		return
	}
	var total int
	if err := s.db.QueryRow("SELECT count(*) FROM purchase WHERE customer_id = $1", customerID).Scan(&total); err != nil {
		serverError(w, err)
		return
	}

	items := []purchase{}
	if offset, ok := pg.offset(total); ok {
		rows, err := s.db.Query(`SELECT id, created_at, total_cents FROM purchase
			WHERE customer_id = $1 ORDER BY created_at DESC, id DESC LIMIT $2 OFFSET $3`,
			customerID, pg.size, offset)
		if err != nil {
			serverError(w, err)
			return
		}
		for rows.Next() {
			p, err := scanPurchase(rows)
			if err != nil {
				rows.Close()
				serverError(w, err)
				return
			}
			items = append(items, p)
		}
		rows.Close()
		if err := rows.Err(); err != nil {
			serverError(w, err)
			return
		}
		for i := range items {
			if err := s.loadLines(&items[i]); err != nil {
				serverError(w, err)
				return
			}
		}
	}
	writeList(w, items, total, pg)
}

func (s *server) getPurchase(w http.ResponseWriter, r *http.Request, customerID int) {
	id, ok := parseID(r.PathValue("id"))
	if !ok {
		writeError(w, http.StatusNotFound, "purchase not found")
		return
	}
	p, err := scanPurchase(s.db.QueryRow(`SELECT id, created_at, total_cents FROM purchase
		WHERE id = $1 AND customer_id = $2`, id, customerID))
	if errors.Is(err, sql.ErrNoRows) {
		writeError(w, http.StatusNotFound, "purchase not found")
		return
	}
	if err != nil {
		serverError(w, err)
		return
	}
	if err := s.loadLines(&p); err != nil {
		serverError(w, err)
		return
	}
	writeJSON(w, http.StatusOK, p)
}

type orderLine struct {
	bookID   int
	quantity int
}

type lockedBook struct {
	title      string
	priceCents int
	stock      int
	published  bool
}

// lockBooks locks the given book rows in id order until the transaction ends.
func lockBooks(ctx context.Context, tx *sql.Tx, ids []int) (map[int]lockedBook, error) {
	placeholders := make([]string, len(ids))
	args := make([]any, len(ids))
	for i, id := range ids {
		placeholders[i] = fmt.Sprintf("$%d", i+1)
		args[i] = id
	}
	rows, err := tx.QueryContext(ctx, `SELECT id, title, price_cents, stock, published FROM book
		WHERE id IN (`+strings.Join(placeholders, ", ")+`) ORDER BY id FOR UPDATE`, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	books := map[int]lockedBook{}
	for rows.Next() {
		var id int
		var b lockedBook
		if err := rows.Scan(&id, &b.title, &b.priceCents, &b.stock, &b.published); err != nil {
			return nil, err
		}
		books[id] = b
	}
	return books, rows.Err()
}

// charge asks the payment service to accept the amount.
func charge(ctx context.Context, customerID, amountCents int) error {
	body, err := json.Marshal(map[string]int{"customerId": customerID, "amountCents": amountCents})
	if err != nil {
		return err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, paymentURL, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	resp, err := paymentClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("payment service answered %d", resp.StatusCode)
	}
	var out struct {
		Status string `json:"status"`
	}
	if err := json.NewDecoder(io.LimitReader(resp.Body, 1<<20)).Decode(&out); err != nil {
		return err
	}
	switch out.Status {
	case "accepted":
		return nil
	case "declined":
		return errDeclined
	}
	return fmt.Errorf("payment service answered status %q", out.Status)
}

func (s *server) createPurchase(w http.ResponseWriter, r *http.Request, customerID int) {
	var body map[string]any
	dec := json.NewDecoder(r.Body)
	dec.UseNumber()
	if err := dec.Decode(&body); err != nil {
		writeError(w, http.StatusBadRequest, "invalid JSON body")
		return
	}
	rawLines, ok := body["lines"].([]any)
	if !ok || len(rawLines) == 0 {
		writeError(w, http.StatusUnprocessableEntity, "lines must be a non-empty list")
		return
	}
	lines := make([]orderLine, 0, len(rawLines))
	for _, raw := range rawLines {
		obj, ok := raw.(map[string]any)
		if !ok {
			writeError(w, http.StatusUnprocessableEntity, "each line must be an object")
			return
		}
		bookID, err := intValue(obj["bookId"])
		if err != nil || bookID < 1 {
			writeError(w, http.StatusUnprocessableEntity, "bookId must be an integer")
			return
		}
		quantity, err := intValue(obj["quantity"])
		if err != nil || quantity < 1 {
			writeError(w, http.StatusUnprocessableEntity, "quantity must be an integer >= 1")
			return
		}
		lines = append(lines, orderLine{bookID: bookID, quantity: quantity})
	}

	ids := []int{}
	for _, l := range lines {
		if !slices.Contains(ids, l.bookID) {
			ids = append(ids, l.bookID)
		}
	}
	slices.Sort(ids)

	ctx := r.Context()
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		serverError(w, err)
		return
	}
	defer tx.Rollback()

	books, err := lockBooks(ctx, tx, ids)
	if err != nil {
		serverError(w, err)
		return
	}
	for _, l := range lines {
		if b, ok := books[l.bookID]; !ok || !b.published {
			writeError(w, http.StatusUnprocessableEntity, fmt.Sprintf("book %d is not available", l.bookID))
			return
		}
	}
	needed := map[int]int{}
	total := 0
	for _, l := range lines {
		b := books[l.bookID]
		// Checked per line so the running sum never exceeds the stock.
		if l.quantity > b.stock-needed[l.bookID] {
			writeError(w, http.StatusConflict, fmt.Sprintf("not enough stock for book %d", l.bookID))
			return
		}
		needed[l.bookID] += l.quantity
		total += l.quantity * b.priceCents
	}

	if err := charge(ctx, customerID, total); err != nil {
		if errors.Is(err, errDeclined) {
			writeError(w, http.StatusPaymentRequired, "payment declined")
			return
		}
		writeError(w, http.StatusBadGateway, "payment service unavailable")
		return
	}

	p := purchase{TotalCents: total, Lines: []purchaseLine{}}
	var createdAt time.Time
	err = tx.QueryRowContext(ctx, `INSERT INTO purchase (customer_id, created_at, total_cents)
		VALUES ($1, now(), $2) RETURNING id, created_at`, customerID, total).Scan(&p.ID, &createdAt)
	if err != nil {
		serverError(w, err)
		return
	}
	for _, l := range lines {
		b := books[l.bookID]
		if _, err := tx.ExecContext(ctx, `INSERT INTO purchase_line
			(purchase_id, book_id, quantity, unit_price_cents) VALUES ($1, $2, $3, $4)`,
			p.ID, l.bookID, l.quantity, b.priceCents); err != nil {
			serverError(w, err)
			return
		}
		p.Lines = append(p.Lines, purchaseLine{
			BookID: l.bookID, Title: b.title, Quantity: l.quantity, UnitPriceCents: b.priceCents,
		})
	}
	for _, id := range ids {
		if _, err := tx.ExecContext(ctx, "UPDATE book SET stock = stock - $1 WHERE id = $2", needed[id], id); err != nil {
			serverError(w, err)
			return
		}
	}
	if err := tx.Commit(); err != nil {
		serverError(w, err)
		return
	}
	p.CreatedAt = createdAt.UTC().Format(time.RFC3339)
	writeJSON(w, http.StatusCreated, p)
}
