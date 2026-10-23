package main

import (
	"database/sql"
	"log"
	"net/http"
	"time"

	_ "github.com/jackc/pgx/v5/stdlib"
)

const dsn = "postgres://eval_rw:eval_rw@postgres:5432/llmapi_eval"

type server struct {
	db *sql.DB
}

func main() {
	db, err := sql.Open("pgx", dsn)
	if err != nil {
		log.Fatal(err)
	}
	if err := db.Ping(); err != nil {
		log.Fatal(err)
	}

	s := &server{db: db}
	mux := http.NewServeMux()
	mux.HandleFunc("GET /authors", s.listAuthors)
	mux.HandleFunc("GET /authors/{id}", s.getAuthor)
	mux.HandleFunc("GET /books", s.listBooks)
	mux.HandleFunc("GET /books/{id}", s.getBook)
	mux.HandleFunc("PATCH /books/{id}", s.patchBook)
	mux.HandleFunc("GET /purchases", s.requireCustomer(s.listPurchases))
	mux.HandleFunc("GET /purchases/{id}", s.requireCustomer(s.getPurchase))
	mux.HandleFunc("POST /purchases", s.requireCustomer(s.createPurchase))
	mux.HandleFunc("GET /reports/sales", s.salesReport)
	mux.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		writeError(w, http.StatusNotFound, "not found")
	})

	srv := &http.Server{
		Addr:              ":8089",
		Handler:           mux,
		ReadHeaderTimeout: 10 * time.Second,
	}
	log.Fatal(srv.ListenAndServe())
}
