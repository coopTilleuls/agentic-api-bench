use std::{
    collections::{BTreeMap, HashMap},
    time::Duration,
};

use axum::{
    Json, Router,
    body::Bytes,
    extract::{Path, State},
    http::{HeaderMap, StatusCode, Uri, header},
    response::{IntoResponse, Response},
    routing::get,
};
use chrono::{DateTime, NaiveDate, SecondsFormat, Utc};
use deadpool_postgres::{Manager, ManagerConfig, Pool, RecyclingMethod};
use serde_json::{Value, json};
use tokio::net::TcpListener;
use tokio_postgres::{Client, NoTls, Row, types::ToSql};

const DSN: &str = "postgres://eval_rw:eval_rw@postgres:5432/llmapi_eval";
const PAYMENT_URL: &str = "http://127.0.0.1:18090/payments";

#[derive(Clone)]
struct AppState {
    pool: Pool,
    http: reqwest::Client,
}

#[derive(Debug)]
enum ApiError {
    BadRequest(String),
    Unauthorized,
    NotFound,
    Unprocessable(String),
    Conflict(String),
    PaymentDeclined,
    BadGateway(String),
    Internal(String),
}

impl IntoResponse for ApiError {
    fn into_response(self) -> Response {
        let (status, message) = match self {
            Self::BadRequest(m) => (StatusCode::BAD_REQUEST, m),
            Self::Unauthorized => (
                StatusCode::UNAUTHORIZED,
                "missing or unknown customer".to_string(),
            ),
            Self::NotFound => (StatusCode::NOT_FOUND, "not found".to_string()),
            Self::Unprocessable(m) => (StatusCode::UNPROCESSABLE_ENTITY, m),
            Self::Conflict(m) => (StatusCode::CONFLICT, m),
            Self::PaymentDeclined => (StatusCode::PAYMENT_REQUIRED, "payment declined".to_string()),
            Self::BadGateway(m) => (StatusCode::BAD_GATEWAY, m),
            Self::Internal(m) => (StatusCode::INTERNAL_SERVER_ERROR, m),
        };
        (status, Json(json!({ "error": message }))).into_response()
    }
}

impl From<tokio_postgres::Error> for ApiError {
    fn from(e: tokio_postgres::Error) -> Self {
        Self::Internal(e.to_string())
    }
}

impl From<deadpool_postgres::PoolError> for ApiError {
    fn from(e: deadpool_postgres::PoolError) -> Self {
        Self::Internal(e.to_string())
    }
}

type ApiResult = Result<Response, ApiError>;

fn ok(value: Value) -> ApiResult {
    Ok(Json(value).into_response())
}

fn query_pairs(uri: &Uri) -> Vec<(String, String)> {
    form_urlencoded::parse(uri.query().unwrap_or("").as_bytes())
        .into_owned()
        .collect()
}

fn parse_id(raw: &str) -> Result<i32, ApiError> {
    raw.parse::<i32>().map_err(|_| ApiError::NotFound)
}

fn parse_date(raw: &str) -> Option<NaiveDate> {
    let bytes = raw.as_bytes();
    if bytes.len() != 10 || bytes[4] != b'-' || bytes[7] != b'-' {
        return None;
    }
    if !raw
        .bytes()
        .enumerate()
        .all(|(i, c)| i == 4 || i == 7 || c.is_ascii_digit())
    {
        return None;
    }
    NaiveDate::parse_from_str(raw, "%Y-%m-%d").ok()
}

fn date_param(value: &str, name: &str) -> Result<NaiveDate, ApiError> {
    parse_date(value).ok_or_else(|| ApiError::BadRequest(format!("{name} must be a YYYY-MM-DD date")))
}

fn int_param(value: &str, name: &str) -> Result<i64, ApiError> {
    value
        .parse::<i64>()
        .map_err(|_| ApiError::BadRequest(format!("{name} must be an integer")))
}

struct Page {
    number: i64,
    size: i64,
}

impl Page {
    fn offset(&self) -> i64 {
        (self.number - 1).saturating_mul(self.size)
    }

    fn envelope(&self, items: Vec<Value>, total: i64) -> Value {
        json!({
            "items": items,
            "total": total,
            "page": self.number,
            "itemsPerPage": self.size,
        })
    }
}

fn parse_page(params: &[(String, String)]) -> Result<Page, ApiError> {
    let mut page = Page { number: 1, size: 10 };
    for (key, value) in params {
        match key.as_str() {
            "page" => {
                page.number = int_param(value, "page")?;
                if page.number < 1 {
                    return Err(ApiError::BadRequest("page must be at least 1".into()));
                }
            }
            "itemsPerPage" => {
                page.size = int_param(value, "itemsPerPage")?;
                if !(1..=50).contains(&page.size) {
                    return Err(ApiError::BadRequest(
                        "itemsPerPage must be between 1 and 50".into(),
                    ));
                }
            }
            _ => {}
        }
    }
    Ok(page)
}

/// Positional SQL parameters, built as filters are added.
#[derive(Default)]
struct Args(Vec<Box<dyn ToSql + Sync + Send>>);

impl Args {
    fn add<T: ToSql + Sync + Send + 'static>(&mut self, value: T) -> String {
        self.0.push(Box::new(value));
        format!("${}", self.0.len())
    }

    fn refs(&self) -> Vec<&(dyn ToSql + Sync)> {
        self.0.iter().map(|b| &**b as &(dyn ToSql + Sync)).collect()
    }
}

const BOOK_SELECT: &str = "SELECT b.id, b.title, b.price_cents, b.published_at, b.stock, a.id, a.name \
     FROM book b JOIN author a ON a.id = b.author_id";

fn book_json(row: &Row) -> Value {
    json!({
        "id": row.get::<_, i32>(0),
        "title": row.get::<_, String>(1),
        "priceCents": row.get::<_, i32>(2),
        "publishedAt": row.get::<_, Option<NaiveDate>>(3).map(|d| d.to_string()),
        "stock": row.get::<_, i32>(4),
        "author": {
            "id": row.get::<_, i32>(5),
            "name": row.get::<_, Option<String>>(6),
        },
    })
}

// ---------- Authors ----------

async fn list_authors(State(st): State<AppState>, uri: Uri) -> ApiResult {
    let page = parse_page(&query_pairs(&uri))?;
    let client = st.pool.get().await?;
    let total: i64 = client
        .query_one("SELECT count(*) FROM author", &[])
        .await?
        .get(0);
    let rows = client
        .query(
            "SELECT a.id, a.name, \
             (SELECT count(*) FROM book b WHERE b.author_id = a.id AND b.published) \
             FROM author a ORDER BY a.id LIMIT $1 OFFSET $2",
            &[&page.size, &page.offset()],
        )
        .await?;
    let items = rows
        .iter()
        .map(|r| {
            json!({
                "id": r.get::<_, i32>(0),
                "name": r.get::<_, Option<String>>(1),
                "publishedBooks": r.get::<_, i64>(2),
            })
        })
        .collect();
    ok(page.envelope(items, total))
}

async fn get_author(State(st): State<AppState>, Path(id): Path<String>) -> ApiResult {
    let id = parse_id(&id)?;
    let client = st.pool.get().await?;
    let author = client
        .query_opt("SELECT id, name FROM author WHERE id = $1", &[&id])
        .await?
        .ok_or(ApiError::NotFound)?;
    let books = client
        .query(
            "SELECT id, title FROM book WHERE author_id = $1 AND published ORDER BY id",
            &[&id],
        )
        .await?;
    let books: Vec<Value> = books
        .iter()
        .map(|r| json!({"id": r.get::<_, i32>(0), "title": r.get::<_, String>(1)}))
        .collect();
    ok(json!({
        "id": author.get::<_, i32>(0),
        "name": author.get::<_, Option<String>>(1),
        "books": books,
    }))
}

// ---------- Books ----------

async fn list_books(State(st): State<AppState>, uri: Uri) -> ApiResult {
    let params = query_pairs(&uri);
    let page = parse_page(&params)?;
    let mut conds = vec!["b.published".to_string()];
    let mut args = Args::default();
    let mut orders = Vec::new();

    for (key, value) in &params {
        match key.as_str() {
            "title" => {
                let p = args.add(value.clone());
                conds.push(format!("strpos(lower(b.title), lower({p})) > 0"));
            }
            "author" => {
                let p = args.add(value.clone());
                conds.push(format!("a.name = {p}"));
            }
            "priceCents[gte]" => {
                let p = args.add(int_param(value, "priceCents[gte]")?);
                conds.push(format!("b.price_cents >= {p}::bigint"));
            }
            "priceCents[lte]" => {
                let p = args.add(int_param(value, "priceCents[lte]")?);
                conds.push(format!("b.price_cents <= {p}::bigint"));
            }
            "publishedAt[after]" => {
                let p = args.add(date_param(value, "publishedAt[after]")?);
                conds.push(format!("b.published_at >= {p}"));
            }
            "publishedAt[before]" => {
                let p = args.add(date_param(value, "publishedAt[before]")?);
                conds.push(format!("b.published_at <= {p}"));
            }
            "inStock" => match value.as_str() {
                "true" => conds.push("b.stock > 0".into()),
                "false" => conds.push("b.stock = 0".into()),
                _ => return Err(ApiError::BadRequest("inStock must be true or false".into())),
            },
            _ => {
                if let Some(field) = key
                    .strip_prefix("order[")
                    .and_then(|k| k.strip_suffix(']'))
                {
                    let column = match field {
                        "priceCents" => "b.price_cents",
                        "title" => "b.title",
                        "publishedAt" => "b.published_at",
                        _ => {
                            return Err(ApiError::BadRequest(format!(
                                "cannot sort by {field}"
                            )));
                        }
                    };
                    let direction = match value.as_str() {
                        "asc" => "ASC",
                        "desc" => "DESC",
                        _ => {
                            return Err(ApiError::BadRequest(
                                "sort direction must be asc or desc".into(),
                            ));
                        }
                    };
                    orders.push(format!("{column} {direction}"));
                }
            }
        }
    }
    orders.push("b.id ASC".into());

    let client = st.pool.get().await?;
    let where_sql = conds.join(" AND ");
    let total: i64 = client
        .query_one(
            &format!(
                "SELECT count(*) FROM book b JOIN author a ON a.id = b.author_id WHERE {where_sql}"
            ),
            &args.refs(),
        )
        .await?
        .get(0);

    let limit = args.add(page.size);
    let offset = args.add(page.offset());
    let rows = client
        .query(
            &format!(
                "{BOOK_SELECT} WHERE {where_sql} ORDER BY {} LIMIT {limit} OFFSET {offset}",
                orders.join(", ")
            ),
            &args.refs(),
        )
        .await?;
    ok(page.envelope(rows.iter().map(book_json).collect(), total))
}

async fn get_book(State(st): State<AppState>, Path(id): Path<String>) -> ApiResult {
    let id = parse_id(&id)?;
    let client = st.pool.get().await?;
    let row = client
        .query_opt(&format!("{BOOK_SELECT} WHERE b.published AND b.id = $1"), &[&id])
        .await?
        .ok_or(ApiError::NotFound)?;
    ok(book_json(&row))
}

fn optional_non_negative(value: Option<&Value>, name: &str) -> Result<Option<i32>, ApiError> {
    match value {
        None => Ok(None),
        Some(v) => v
            .as_i64()
            .and_then(|n| i32::try_from(n).ok())
            .filter(|n| *n >= 0)
            .map(Some)
            .ok_or_else(|| ApiError::Unprocessable(format!("{name} must be a non-negative integer"))),
    }
}

async fn patch_book(State(st): State<AppState>, Path(id): Path<String>, body: Bytes) -> ApiResult {
    let id = parse_id(&id)?;
    let client = st.pool.get().await?;
    let published: bool = client
        .query_opt("SELECT published FROM book WHERE id = $1", &[&id])
        .await?
        .ok_or(ApiError::NotFound)?
        .get(0);

    let body: Value = serde_json::from_slice(&body)
        .map_err(|_| ApiError::BadRequest("invalid JSON body".into()))?;
    let fields = body
        .as_object()
        .ok_or_else(|| ApiError::BadRequest("body must be a JSON object".into()))?;

    let title = match fields.get("title") {
        None => None,
        Some(Value::String(t)) if !t.trim().is_empty() => Some(t.clone()),
        Some(_) => {
            return Err(ApiError::Unprocessable(
                "title must be a non-empty string".into(),
            ));
        }
    };
    let price = optional_non_negative(fields.get("priceCents"), "priceCents")?;
    let stock = optional_non_negative(fields.get("stock"), "stock")?;

    client
        .execute(
            "UPDATE book SET title = COALESCE($1, title), price_cents = COALESCE($2, price_cents), \
             stock = COALESCE($3, stock) WHERE id = $4",
            &[&title, &price, &stock, &id],
        )
        .await?;

    if !published {
        return Ok(StatusCode::NO_CONTENT.into_response());
    }
    let row = client
        .query_one(&format!("{BOOK_SELECT} WHERE b.id = $1"), &[&id])
        .await?;
    ok(book_json(&row))
}

// ---------- Purchases ----------

async fn authenticate(client: &Client, headers: &HeaderMap) -> Result<i32, ApiError> {
    let id = headers
        .get("customer-id")
        .and_then(|v| v.to_str().ok())
        .and_then(|v| v.parse::<i32>().ok())
        .ok_or(ApiError::Unauthorized)?;
    client
        .query_opt("SELECT id FROM customer WHERE id = $1", &[&id])
        .await?
        .ok_or(ApiError::Unauthorized)?;
    Ok(id)
}

async fn load_lines(client: &Client, purchase_ids: Vec<i32>) -> Result<HashMap<i32, Vec<Value>>, ApiError> {
    let rows = client
        .query(
            "SELECT pl.purchase_id, pl.book_id, b.title, pl.quantity, pl.unit_price_cents \
             FROM purchase_line pl JOIN book b ON b.id = pl.book_id \
             WHERE pl.purchase_id = ANY($1) ORDER BY pl.id",
            &[&purchase_ids],
        )
        .await?;
    let mut lines: HashMap<i32, Vec<Value>> = HashMap::new();
    for r in &rows {
        lines.entry(r.get(0)).or_default().push(json!({
            "bookId": r.get::<_, i32>(1),
            "title": r.get::<_, String>(2),
            "quantity": r.get::<_, i32>(3),
            "unitPriceCents": r.get::<_, i32>(4),
        }));
    }
    Ok(lines)
}

fn purchase_json(id: i32, created_at: DateTime<Utc>, total: i32, lines: Vec<Value>) -> Value {
    json!({
        "id": id,
        "createdAt": created_at.to_rfc3339_opts(SecondsFormat::Secs, true),
        "totalCents": total,
        "lines": lines,
    })
}

async fn list_purchases(State(st): State<AppState>, headers: HeaderMap, uri: Uri) -> ApiResult {
    let client = st.pool.get().await?;
    let customer = authenticate(&client, &headers).await?;
    let page = parse_page(&query_pairs(&uri))?;
    let total: i64 = client
        .query_one(
            "SELECT count(*) FROM purchase WHERE customer_id = $1",
            &[&customer],
        )
        .await?
        .get(0);
    let rows = client
        .query(
            "SELECT id, created_at, total_cents FROM purchase WHERE customer_id = $1 \
             ORDER BY created_at DESC, id DESC LIMIT $2 OFFSET $3",
            &[&customer, &page.size, &page.offset()],
        )
        .await?;
    let ids: Vec<i32> = rows.iter().map(|r| r.get(0)).collect();
    let mut lines = load_lines(&client, ids).await?;
    let items = rows
        .iter()
        .map(|r| {
            let id: i32 = r.get(0);
            purchase_json(
                id,
                r.get(1),
                r.get(2),
                lines.remove(&id).unwrap_or_default(),
            )
        })
        .collect();
    ok(page.envelope(items, total))
}

async fn get_purchase(State(st): State<AppState>, headers: HeaderMap, Path(id): Path<String>) -> ApiResult {
    let client = st.pool.get().await?;
    let customer = authenticate(&client, &headers).await?;
    let id = parse_id(&id)?;
    let row = client
        .query_opt(
            "SELECT id, created_at, total_cents FROM purchase WHERE id = $1 AND customer_id = $2",
            &[&id, &customer],
        )
        .await?
        .ok_or(ApiError::NotFound)?;
    let mut lines = load_lines(&client, vec![id]).await?;
    ok(purchase_json(
        id,
        row.get(1),
        row.get(2),
        lines.remove(&id).unwrap_or_default(),
    ))
}

/// Asks the payment service to charge the customer; only `Ok` means accepted.
async fn charge(http: &reqwest::Client, customer: i32, amount_cents: i64) -> Result<(), ApiError> {
    let response = http
        .post(PAYMENT_URL)
        .json(&json!({"customerId": customer, "amountCents": amount_cents}))
        .send()
        .await
        .map_err(|e| ApiError::BadGateway(format!("payment service unreachable: {e}")))?;
    if !response.status().is_success() {
        return Err(ApiError::BadGateway(format!(
            "payment service answered {}",
            response.status()
        )));
    }
    let body: Value = response
        .json()
        .await
        .map_err(|_| ApiError::BadGateway("invalid payment service response".into()))?;
    match body.get("status").and_then(Value::as_str) {
        Some("accepted") => Ok(()),
        Some("declined") => Err(ApiError::PaymentDeclined),
        _ => Err(ApiError::BadGateway("unexpected payment service response".into())),
    }
}

struct BookInfo {
    title: String,
    price: i32,
    stock: i32,
}

async fn create_purchase(State(st): State<AppState>, headers: HeaderMap, body: Bytes) -> ApiResult {
    let mut client = st.pool.get().await?;
    let customer = authenticate(&client, &headers).await?;
    let body: Value = serde_json::from_slice(&body)
        .map_err(|_| ApiError::BadRequest("invalid JSON body".into()))?;

    let lines = body
        .get("lines")
        .and_then(Value::as_array)
        .ok_or_else(|| ApiError::Unprocessable("lines must be an array".into()))?;
    if lines.is_empty() {
        return Err(ApiError::Unprocessable("lines must not be empty".into()));
    }
    let mut requested: Vec<(i32, i64)> = Vec::with_capacity(lines.len());
    for line in lines {
        let book = line
            .get("bookId")
            .and_then(Value::as_i64)
            .and_then(|v| i32::try_from(v).ok())
            .ok_or_else(|| ApiError::Unprocessable("bookId must be an integer".into()))?;
        let quantity = line
            .get("quantity")
            .and_then(Value::as_i64)
            .filter(|q| *q >= 1)
            .ok_or_else(|| ApiError::Unprocessable("quantity must be an integer of at least 1".into()))?;
        requested.push((book, quantity));
    }

    // Total quantity per book, across all lines.
    let mut wanted: BTreeMap<i32, i64> = BTreeMap::new();
    for (book, quantity) in &requested {
        let entry = wanted.entry(*book).or_default();
        *entry = entry.saturating_add(*quantity);
    }
    let ids: Vec<i32> = wanted.keys().copied().collect();

    let tx = client.transaction().await?;
    // Lock the books so that stock checks and decrements cannot race.
    let rows = tx
        .query(
            "SELECT id, title, price_cents, stock, published FROM book \
             WHERE id = ANY($1) ORDER BY id FOR UPDATE",
            &[&ids],
        )
        .await?;
    let mut books: HashMap<i32, BookInfo> = HashMap::new();
    for r in &rows {
        if !r.get::<_, bool>(4) {
            continue;
        }
        books.insert(
            r.get(0),
            BookInfo {
                title: r.get(1),
                price: r.get(2),
                stock: r.get(3),
            },
        );
    }
    if books.len() != ids.len() {
        return Err(ApiError::Unprocessable(
            "every line must reference a published book".into(),
        ));
    }
    for (id, quantity) in &wanted {
        if *quantity > books[id].stock as i64 {
            return Err(ApiError::Conflict(format!("not enough stock for book {id}")));
        }
    }

    let total: i64 = requested
        .iter()
        .map(|(book, quantity)| quantity * books[book].price as i64)
        .sum();
    if total > i32::MAX as i64 {
        return Err(ApiError::Unprocessable("total is too large".into()));
    }

    charge(&st.http, customer, total).await?;

    let purchase = tx
        .query_one(
            "INSERT INTO purchase (customer_id, total_cents) VALUES ($1, $2) \
             RETURNING id, created_at",
            &[&customer, &(total as i32)],
        )
        .await?;
    let purchase_id: i32 = purchase.get(0);
    let created_at: DateTime<Utc> = purchase.get(1);

    let mut out_lines = Vec::with_capacity(requested.len());
    for (book, quantity) in &requested {
        let info = &books[book];
        tx.execute(
            "INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents) \
             VALUES ($1, $2, $3, $4)",
            &[&purchase_id, book, &(*quantity as i32), &info.price],
        )
        .await?;
        out_lines.push(json!({
            "bookId": book,
            "title": info.title,
            "quantity": quantity,
            "unitPriceCents": info.price,
        }));
    }
    for (book, quantity) in &wanted {
        tx.execute(
            "UPDATE book SET stock = stock - $1 WHERE id = $2",
            &[&(*quantity as i32), book],
        )
        .await?;
    }
    tx.commit().await?;

    Ok((
        StatusCode::CREATED,
        Json(purchase_json(purchase_id, created_at, total as i32, out_lines)),
    )
        .into_response())
}

// ---------- Reports ----------

fn csv_field(value: &str) -> String {
    if value.contains([',', '"', '\n', '\r']) {
        format!("\"{}\"", value.replace('"', "\"\""))
    } else {
        value.to_string()
    }
}

async fn sales_report(State(st): State<AppState>, uri: Uri) -> ApiResult {
    let params = query_pairs(&uri);
    let find = |name: &str| -> Result<NaiveDate, ApiError> {
        let raw = params
            .iter()
            .rev()
            .find(|(k, _)| k == name)
            .map(|(_, v)| v.as_str())
            .ok_or_else(|| ApiError::BadRequest(format!("{name} is required")))?;
        date_param(raw, name)
    };
    let from = find("from")?;
    let to = find("to")?;
    if from > to {
        return Err(ApiError::BadRequest("from must not be after to".into()));
    }
    let start = from.and_hms_opt(0, 0, 0).unwrap().and_utc();
    let end = to
        .succ_opt()
        .ok_or_else(|| ApiError::BadRequest("to is out of range".into()))?
        .and_hms_opt(0, 0, 0)
        .unwrap()
        .and_utc();

    let client = st.pool.get().await?;
    let rows = client
        .query(
            "SELECT b.title, SUM(pl.quantity)::bigint, \
             SUM(pl.quantity::bigint * pl.unit_price_cents)::bigint AS revenue, b.id \
             FROM purchase_line pl \
             JOIN purchase p ON p.id = pl.purchase_id \
             JOIN book b ON b.id = pl.book_id \
             WHERE p.created_at >= $1 AND p.created_at < $2 \
             GROUP BY b.id, b.title \
             ORDER BY revenue DESC, b.title ASC, b.id ASC",
            &[&start, &end],
        )
        .await?;

    let mut csv = String::from("title,quantity,revenueCents\n");
    for r in &rows {
        csv.push_str(&format!(
            "{},{},{}\n",
            csv_field(&r.get::<_, String>(0)),
            r.get::<_, i64>(1),
            r.get::<_, i64>(2)
        ));
    }
    Ok((
        [
            (header::CONTENT_TYPE, "text/csv; charset=utf-8"),
            (header::CONTENT_DISPOSITION, "attachment; filename=\"sales.csv\""),
        ],
        csv,
    )
        .into_response())
}

#[tokio::main]
async fn main() {
    let config: tokio_postgres::Config = DSN.parse().expect("valid DSN");
    let manager = Manager::from_config(
        config,
        NoTls,
        ManagerConfig {
            recycling_method: RecyclingMethod::Fast,
        },
    );
    let pool = Pool::builder(manager)
        .max_size(16)
        .build()
        .expect("pool");
    let http = reqwest::Client::builder()
        .no_proxy()
        .timeout(Duration::from_secs(10))
        .build()
        .expect("http client");

    let app = Router::new()
        .route("/authors", get(list_authors))
        .route("/authors/{id}", get(get_author))
        .route("/books", get(list_books))
        .route("/books/{id}", get(get_book).patch(patch_book))
        .route("/purchases", get(list_purchases).post(create_purchase))
        .route("/purchases/{id}", get(get_purchase))
        .route("/reports/sales", get(sales_report))
        .fallback(|| async { ApiError::NotFound })
        .with_state(AppState { pool, http });

    let listener = match TcpListener::bind("[::]:8089").await {
        Ok(l) => l,
        Err(_) => TcpListener::bind("0.0.0.0:8089")
            .await
            .expect("bind port 8089"),
    };
    axum::serve(listener, app).await.expect("server");
}
