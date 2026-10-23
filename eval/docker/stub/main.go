// External services of the specs, on 127.0.0.1:18090 inside the container:
// GET /quotes.json (eval/spec.md), POST /payments (eval/v2/spec.md, rules in
// eval/v2/README.md). Static binary: the image has no interpreter.
package main

import (
	"bytes"
	"encoding/json"
	"io"
	"log"
	"math"
	"net/http"
	"os"
	"sync"
)

const (
	declineAboveCents = 50000
	failingCustomerID = 3
)

type entry struct {
	Request  any `json:"request"`
	Status   int `json:"status"`
	Response any `json:"response"`
}

type server struct {
	quotes   []byte
	mu       sync.Mutex
	payments []entry
}

func writeJSON(w http.ResponseWriter, status int, doc any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf)
	enc.SetEscapeHTML(false)
	_ = enc.Encode(doc)
	_, _ = w.Write(buf.Bytes())
}

func integer(v any) (float64, bool) {
	n, ok := v.(json.Number)
	if !ok {
		return 0, false
	}
	f, err := n.Float64()
	if err != nil || math.IsInf(f, 0) || f != math.Trunc(f) {
		return 0, false
	}
	return f, true
}

func (s *server) pay(w http.ResponseWriter, r *http.Request) {
	raw, _ := io.ReadAll(r.Body)
	var parsed any
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.UseNumber()
	var logged any = string(raw)
	if err := dec.Decode(&parsed); err == nil && !dec.More() {
		logged = parsed
	} else {
		parsed = nil
	}

	var customer, amount float64
	valid := false
	if m, ok := parsed.(map[string]any); ok {
		c, cok := integer(m["customerId"])
		a, aok := integer(m["amountCents"])
		customer, amount, valid = c, a, cok && aok && a >= 0
	}

	status := http.StatusBadRequest
	var doc any = map[string]string{"error": `expected {"customerId": <integer>, "amountCents": <integer >= 0>}`}
	switch {
	case valid && customer == failingCustomerID:
		status, doc = http.StatusInternalServerError, map[string]string{"error": "internal error"}
	case valid:
		result := "accepted"
		if amount > declineAboveCents {
			result = "declined"
		}
		status, doc = http.StatusOK, map[string]string{"status": result}
	}
	s.mu.Lock()
	s.payments = append(s.payments, entry{Request: logged, Status: status, Response: doc})
	s.mu.Unlock()
	writeJSON(w, status, doc)
}

func (s *server) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	switch {
	case r.URL.Path == "/quotes.json":
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write(s.quotes)
	case r.URL.Path == "/payments" && r.Method == http.MethodPost:
		s.pay(w, r)
	case r.URL.Path == "/_stub/payments" && r.Method == http.MethodGet:
		s.mu.Lock()
		list := append([]entry{}, s.payments...)
		s.mu.Unlock()
		writeJSON(w, http.StatusOK, list)
	case r.URL.Path == "/_stub/payments" && r.Method == http.MethodDelete:
		s.mu.Lock()
		s.payments = nil
		s.mu.Unlock()
		w.WriteHeader(http.StatusNoContent)
	default:
		w.Header().Set("Content-Type", "text/plain")
		w.WriteHeader(http.StatusNotFound)
		_, _ = w.Write([]byte("not found\n"))
	}
}

func getenv(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}

func main() {
	quotes, err := os.ReadFile(getenv("STUB_QUOTES", "/opt/stub/quotes.json"))
	if err != nil {
		log.Fatal(err)
	}
	addr := "127.0.0.1:" + getenv("STUB_PORT", "18090")
	log.Fatal(http.ListenAndServe(addr, &server{quotes: quotes}))
}
