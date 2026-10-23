package main

import "testing"

func TestExtractSQLGoRawStrings(t *testing.T) {
	src := "package main\n" +
		"const q = `SELECT o.id FROM orders o JOIN customers c ON c.id = o.customer_id WHERE o.status = $1 AND c.x = $2`\n" +
		"const esc = `SELECT id FROM customers WHERE name LIKE '%\\`\n" +
		"func f(a, b int) int { return a / b }\n" +
		"var r = '\"'\n" +
		"var ins = \"INSERT INTO customers (name) VALUES ($1)\"\n"
	got := extractSQL("main.go", src)
	if len(got) != 3 {
		t.Fatalf("want 3 statements, got %d: %+v", len(got), got)
	}
}

func TestExtractSQLRustStrings(t *testing.T) {
	src := "struct H<'a> { n: &'a str }\n" +
		"fn l<'a>(x: &'a str) -> &'a str { x }\n" +
		"const Q: &str = r#\"SELECT \"o\".\"id\" FROM orders o JOIN customers c ON c.id = o.customer_id WHERE o.a = $1 AND o.b <> \"x\"\"#;\n" +
		"fn c() -> char { '\"' }\n" +
		"fn u() { let q = \"UPDATE customers SET name = $1 WHERE id = $2\"; }\n"
	got := extractSQL("main.rs", src)
	if len(got) != 2 {
		t.Fatalf("want 2 statements, got %d: %+v", len(got), got)
	}
	if got[0].Joins != 1 || got[0].Where != 2 {
		t.Fatalf("select: joins %d where %d", got[0].Joins, got[0].Where)
	}
}
