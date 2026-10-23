package testdb

// The bookshop of eval/v2: business SQL by hand, plumbing generated.
var ShopFiles = map[string]string{
	"Author.json": `{
  "name": "Author", "table": "author", "query": "author.query.ts",
  "relations": {"books": {"resource": "Book", "foreignKey": "author_id", "many": true}},
  "operations": [
    {"method": "GET", "path": "/authors", "output": ["id", "name", "publishedBooks"]},
    {"method": "GET", "path": "/authors/{id}", "output": ["id", "name", "books"]}
  ],
  "properties": {
    "id": {"type": "integer", "identifier": true},
    "name": {"type": "string"},
    "publishedBooks": {"type": "integer", "computed": true},
    "books": {"type": "array", "items": {"type": "object", "properties": {"id": {"type": "integer"}, "title": {"type": "string"}}}}
  }
}`,
	"author.query.ts": "export default function query(ctx) {\n" +
		"  const author = ctx.resource(\"Author\");\n" +
		"  const books = author.relation(\"books\");\n" +
		"  const published = ctx.sql`FROM ${books.table()} WHERE ${author.join(\"books\")} AND ${books.column(\"published\")}`;\n" +
		"  return ctx.sql`SELECT ${author.columns()},\n" +
		"    (SELECT count(*)::int ${published}) AS \"publishedBooks\",\n" +
		"    (SELECT coalesce(json_agg(${books.json()} ORDER BY ${books.column(\"id\")}), '[]') ${published}) AS books\n" +
		"    FROM ${author.table()}`;\n" +
		"}",
	"Book.json": `{
  "name": "Book", "path": "/books", "table": "book", "query": "book.query.ts", "itemsPerPage": 10,
  "relations": {"author": {"resource": "Author", "column": "author_id"}},
  "unknownInput": "ignore",
  "parameters": {
    "title": {"in": "query", "filter": "ipartial"},
    "author": {"in": "query", "filter": "exact", "property": "author.name"},
    "priceCents": {"in": "query", "filter": "range"},
    "publishedAt": {"in": "query", "filter": "date"},
    "inStock": {"in": "query", "type": "boolean", "handler": "in_stock.handler.ts"},
    "order": {"in": "query", "filter": "order", "properties": ["priceCents", "title", "publishedAt"]},
    "itemsPerPage": {"in": "query", "type": "integer", "min": 1, "max": 50, "pagination": "itemsPerPage"}
  },
  "input": {
    "title": {"type": "string", "nullable": false, "minLength": 1},
    "priceCents": {"type": "integer", "min": 0},
    "stock": {"type": "integer", "min": 0}
  },
  "properties": {
    "id": {"type": "integer", "identifier": true},
    "title": {"type": "string"},
    "priceCents": {"type": "integer", "column": "price_cents"},
    "publishedAt": {"type": "date", "column": "published_at"},
    "stock": {"type": "integer"},
    "author": {"type": "object", "properties": {"id": {"type": "integer"}, "name": {"type": "string"}}}
  }
}`,
	"book.query.ts": "export default function query(ctx) {\n" +
		"  const book = ctx.resource(\"Book\");\n" +
		"  const author = book.relation(\"author\");\n" +
		"  return ctx.sql`SELECT ${book.columns()}, ${author.columns()}\n" +
		"    FROM ${book.table()} JOIN ${author.table()} ON ${book.join(\"author\")}\n" +
		"    WHERE ${book.column(\"published\")} OR ${ctx.phase === \"lookup\"}`;\n" +
		"}",
	"in_stock.handler.ts": "export default (inStock, ctx) => inStock ? ctx.sql`stock > 0` : ctx.sql`stock = 0`;",
}
