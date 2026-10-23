CREATE TABLE author (
    id serial PRIMARY KEY,
    name text
);

CREATE TABLE book (
    id serial PRIMARY KEY,
    title text NOT NULL,
    author_id integer NOT NULL REFERENCES author (id),
    published boolean NOT NULL DEFAULT false
);

INSERT INTO author (name) VALUES
    ('Ursula K. Le Guin'),
    ('Frank Herbert'),
    ('Octavia E. Butler'),
    (NULL);

INSERT INTO book (title, author_id, published) VALUES
    ('A Wizard of Earthsea', 1, true),
    ('The Left Hand of Darkness', 1, true),
    ('The Dispossessed', 1, false),
    ('Dune', 2, true),
    ('Dune Messiah', 2, true),
    ('Kindred', 3, true),
    ('Parable of the Sower', 3, false),
    ('Untitled Manuscript', 4, false);
