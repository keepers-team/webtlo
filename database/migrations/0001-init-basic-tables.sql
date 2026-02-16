CREATE TABLE IF NOT EXISTS Forums
(
    id INT     NOT NULL PRIMARY KEY,
    na VARCHAR NOT NULL
);

CREATE TABLE IF NOT EXISTS Topics
(
    id INT     NOT NULL PRIMARY KEY,
    ss INT     NOT NULL,
    na VARCHAR NOT NULL,
    hs VARCHAR NOT NULL,
    se INT     NOT NULL,
    si INT     NOT NULL,
    st INT     NOT NULL,
    rg INT     NOT NULL,
    dl INT     NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS Seeders
(
    id  INT NOT NULL PRIMARY KEY,
    d0  INT,
    d1  INT,
    d2  INT,
    d3  INT,
    d4  INT,
    d5  INT,
    d6  INT,
    d7  INT,
    d8  INT,
    d9  INT,
    d10 INT,
    d11 INT,
    d12 INT,
    d13 INT,
    d14 INT,
    d15 INT,
    d16 INT,
    d17 INT,
    d18 INT,
    d19 INT,
    d20 INT,
    d21 INT,
    d22 INT,
    d23 INT,
    d24 INT,
    d25 INT,
    d26 INT,
    d27 INT,
    d28 INT,
    d29 INT,
    q0  INT,
    q1  INT,
    q2  INT,
    q3  INT,
    q4  INT,
    q5  INT,
    q6  INT,
    q7  INT,
    q8  INT,
    q9  INT,
    q10 INT,
    q11 INT,
    q12 INT,
    q13 INT,
    q14 INT,
    q15 INT,
    q16 INT,
    q17 INT,
    q18 INT,
    q19 INT,
    q20 INT,
    q21 INT,
    q22 INT,
    q23 INT,
    q24 INT,
    q25 INT,
    q26 INT,
    q27 INT,
    q28 INT,
    q29 INT
);

CREATE TABLE IF NOT EXISTS Keepers
(
    id       INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    topic_id INTEGER                           NOT NULL,
    nick     VARCHAR                           NOT NULL
);

ALTER TABLE Topics ADD COLUMN rt INT DEFAULT 1;
ALTER TABLE Topics ADD COLUMN ds INT DEFAULT 0;
ALTER TABLE Topics ADD COLUMN cl VARCHAR;

PRAGMA user_version = 1;