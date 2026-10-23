CREATE TABLE author (
    id serial PRIMARY KEY,
    name text
);

CREATE TABLE book (
    id serial PRIMARY KEY,
    title text NOT NULL,
    author_id integer NOT NULL REFERENCES author (id),
    published boolean NOT NULL DEFAULT false,
    price_cents integer NOT NULL,
    published_at date,
    stock integer NOT NULL DEFAULT 0
);

CREATE TABLE customer (
    id serial PRIMARY KEY,
    name text NOT NULL,
    email text NOT NULL UNIQUE
);

CREATE TABLE purchase (
    id serial PRIMARY KEY,
    customer_id integer NOT NULL REFERENCES customer (id),
    created_at timestamptz NOT NULL DEFAULT now(),
    total_cents integer NOT NULL
);

CREATE TABLE purchase_line (
    id serial PRIMARY KEY,
    purchase_id integer NOT NULL REFERENCES purchase (id),
    book_id integer NOT NULL REFERENCES book (id),
    quantity integer NOT NULL,
    unit_price_cents integer NOT NULL
);

INSERT INTO author (id, name) VALUES
    (1, 'Ursula K. Le Guin'),
    (2, 'Frank Herbert'),
    (3, 'Octavia E. Butler'),
    (4, NULL),
    (5, 'Isaac Asimov');

-- 14 published, 4 unpublished. Ties on purpose: price 1000 (5, 14), 1200 (1, 7),
-- 1500 (2, 6); date 2020-06-15 (12, 13), 2021-01-01 (17, 18); title Dune (4, 18).
-- Range bounds: prices 999/1000 and 2000/2001, dates 2019-12-31/2020-01-01 and
-- 2020-12-31/2021-01-01. Stock 0: 2, 6, 12.
INSERT INTO book (id, title, author_id, published, price_cents, published_at, stock) VALUES
    (1, 'A Wizard of Earthsea', 1, true, 1200, '1968-11-01', 5),
    (2, 'The Left Hand of Darkness', 1, true, 1500, '1969-03-01', 0),
    (3, 'The Dispossessed', 1, false, 1800, NULL, 4),
    (4, 'Dune', 2, true, 2000, '1965-08-01', 10),
    (5, 'Dune Messiah', 2, true, 1000, '1969-10-15', 3),
    (6, 'Children of Dune', 2, true, 1500, '1976-04-01', 0),
    (7, 'Kindred', 3, true, 1200, '1979-06-01', 7),
    (8, 'Parable of the Sower', 3, false, 1600, NULL, 2),
    (9, 'Untitled Manuscript', 4, false, 999, NULL, 1),
    (10, 'The Anonymous Chronicle', 4, true, 800, '2020-01-01', 2),
    (11, 'The Road to Dune', 2, true, 2500, '2020-12-31', 6),
    (12, 'Bloodchild', 3, true, 999, '2020-06-15', 0),
    (13, 'Lilith''s Brood', 3, true, 2001, '2020-06-15', 8),
    (14, 'The Lathe of Heaven', 1, true, 1000, '1971-01-01', 1),
    (15, 'Foundation', 5, false, 1300, NULL, 9),
    (16, 'Fledgling', 3, true, 1400, '2019-12-31', 3),
    (17, 'Tehanu', 1, true, 1700, '2021-01-01', 2),
    (18, 'Dune', 2, true, 3500, '2021-01-01', 20);

-- Customer 3 is the one whose payments the stub fails (eval/v2/README.md).
-- Customer 4 has no purchase.
INSERT INTO customer (id, name, email) VALUES
    (1, 'Alice Martin', 'alice@example.com'),
    (2, 'Bob Durand', 'bob@example.com'),
    (3, 'Carol Petit', 'carol@example.com'),
    (4, 'Dan Moreau', 'dan@example.com');

-- Ids are not in created_at order, so "most recent first" differs from id
-- order. Unit prices differ from current prices on lines 5 and 7.
INSERT INTO purchase (id, customer_id, created_at, total_cents) VALUES
    (1, 1, '2026-01-05T10:00:00Z', 5200),
    (2, 2, '2026-01-31T23:30:00Z', 3200),
    (3, 1, '2026-02-01T00:00:00Z', 3000),
    (4, 2, '2026-02-14T12:00:00Z', 10400),
    (5, 1, '2026-01-01T00:00:00Z', 1100),
    (6, 1, '2025-12-31T23:59:59Z', 1500),
    (7, 2, '2026-01-20T09:15:00Z', 1200),
    (8, 3, '2026-02-10T16:45:00Z', 800);

INSERT INTO purchase_line (id, purchase_id, book_id, quantity, unit_price_cents) VALUES
    (1, 1, 4, 2, 2000),
    (2, 1, 7, 1, 1200),
    (3, 2, 4, 1, 2000),
    (4, 2, 1, 1, 1200),
    (5, 5, 1, 1, 1100),
    (6, 3, 5, 3, 1000),
    (7, 7, 13, 1, 1200),
    (8, 4, 7, 2, 1200),
    (9, 4, 11, 1, 2500),
    (10, 4, 18, 1, 3500),
    (11, 4, 4, 1, 2000),
    (12, 6, 2, 1, 1500),
    (13, 8, 10, 1, 800);

SELECT setval('author_id_seq', (SELECT max(id) FROM author));
SELECT setval('book_id_seq', (SELECT max(id) FROM book));
SELECT setval('customer_id_seq', (SELECT max(id) FROM customer));
SELECT setval('purchase_id_seq', (SELECT max(id) FROM purchase));
SELECT setval('purchase_line_id_seq', (SELECT max(id) FROM purchase_line));
