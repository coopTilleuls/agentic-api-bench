# Bookshop API

Build an HTTP API for a small bookshop. The data already exists in a PostgreSQL database (connection details are given below) with two tables:

- `author` (`id`, `name`): `name` may be empty (NULL) for anonymous authors.
- `book` (`id`, `title`, `author_id`, `published`): `published` is true when the book is publicly released.

You must not change the database structure. The API is served on `http://localhost:8089` and must start with `./caddy run`, run from this directory, without any argument. Use only what the `./caddy` program in this directory provides.

## Authors

- List all authors, with their `id` and `name`, 2 per page, in `id` order. The list tells the total number of authors.
- Get one author by id (`/authors/{id}`).
- Create an author by sending its `name` (`POST /authors`); the response is the created author with its `id`.

## Books

Only published books are visible to clients.

- List published books (`/books`), 2 per page in `id` order, selected with `?page=2`, `?page=3`... The list tells the total number of published books. Each book has `id`, `title`, `author` (the author's name) and `published`.
- Filter the list by author name: `/books?author=Frank%20Herbert`.
- Get one published book by id (`/books/{id}`); an unpublished or unknown book answers 404.
- Create a book with `title` (mandatory), `author_id` (mandatory) and `published` (optional, false by default) via `POST /books`. A created published book is returned as it appears in the list.
- Rule: a book whose author has no name cannot be published. Such a request must be refused with HTTP status 422, and nothing must be stored.
- Change the title (or other fields) of a published book with `PATCH /books/{id}`; the response is the updated book.
- Delete a book with `DELETE /books/{id}` (answer 204).

## Quotes

An external service, already running, returns quotes at `http://127.0.0.1:18090/quotes.json`: a JSON array of objects with `id`, `quote` (the text) and `by` (the author's name).

- Expose them as `/quotes`, each with `id`, `text` and `author`, 2 per page, in `id` order, with the total count, filterable by author name (`/quotes?author=...`).
- Get one quote by id (`/quotes/{id}`), 404 when it does not exist.

## Export

- `GET /business/export` downloads a CSV file listing, for every author who has a name, the number of their published books. Columns, in this order: `author`, `published_books`. One line per author, sorted by author name, with a header line.
