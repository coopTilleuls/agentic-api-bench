# Change request

The API in this directory implements the bookshop specification included below. Change it as follows. Everything not mentioned here must keep working exactly as specified.

The database has been migrated: `purchase` has a new column `refunded_at` (timestamp, NULL when the purchase was not refunded). You must not change the database structure further.

1. **Customer header.** Customers now identify themselves with the `Customer-Id` header instead of `X-Customer-Id`. `X-Customer-Id` is no longer accepted (a request carrying only it answers 401).
2. **Page count.** Every list also tells the number of pages: `{"items": [...], "total": ..., "page": ..., "itemsPerPage": ..., "pages": <number of pages, 0 when there are no items>}`.
3. **Book page size.** `GET /books` now has 20 items per page unless the client asks otherwise. Other lists keep 10.
4. **Search.** `GET /books?q=herbert` matches books whose title or author name contains the text, case-insensitive. It combines with every other filter, sorting and pagination.
5. **Authors with published books.** `GET /authors?hasPublishedBooks=true` lists only authors with at least one published book, `false` only those without. Any other value answers 400.
6. **Refunds.** `POST /purchases/{id}/refund` refunds one of the customer's purchases:
   - the stock of each book of the purchase increases back by the quantity bought, and the purchase records the refund time;
   - the answer is 200 with the purchase, which now has `refundedAt` (timestamp, or `null` when not refunded) in every purchase response;
   - another customer's purchase or an unknown one answers 404; a purchase already refunded answers 409 and changes nothing;
   - the payment service is not involved.
7. **Report.** Refunded purchases are excluded from `GET /reports/sales`.
