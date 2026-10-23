QUOTES_URL = "http://127.0.0.1:18090/quotes.json"

def query(ctx):
    res = ctx.fetch(QUOTES_URL)
    if not res.ok:
        ctx.reject("the quotes service answered %d" % res.status, status = 502)
    rows = [{"id": q["id"], "text": q["quote"], "author": q["by"]} for q in res.json()]
    if ctx.operation == "item":
        for row in rows:
            if row["id"] == ctx.id:
                return row
        return None
    if "author" in ctx.filters:
        rows = [row for row in rows if row["author"] == ctx.filters["author"]]
    start = (ctx.page - 1) * ctx.items_per_page
    return {"items": rows[start:start + ctx.items_per_page], "total_items": len(rows)}
