def query(ctx):
    start, end = ctx.get("from"), ctx.get("to")
    if not ctx.validating and start > end:
        ctx.reject("from (%s) is after to (%s)" % (start, end), status = 400)
    return ctx.sql("""
        SELECT b.title, sum(l.quantity)::int AS quantity, sum(l.quantity * l.unit_price_cents)::int AS "revenueCents"
        FROM purchase_line l
        JOIN purchase p ON p.id = l.purchase_id
        JOIN book b ON b.id = l.book_id
        WHERE p.refunded_at IS NULL
          AND p.created_at >= {start}::date::timestamp AT TIME ZONE 'UTC'
          AND p.created_at < ({end}::date + 1)::timestamp AT TIME ZONE 'UTC'
        GROUP BY b.id, b.title
        ORDER BY "revenueCents" DESC, b.title, b.id""", start = start, end = end)
