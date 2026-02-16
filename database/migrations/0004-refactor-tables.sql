-- Меняем структуру таблицы Keepers
ALTER TABLE Keepers RENAME TO KeepersTemp;

CREATE TABLE IF NOT EXISTS Keepers
(
    id     INTEGER NOT NULL,
    nick   VARCHAR NOT NULL,
    posted INTEGER,
    PRIMARY KEY (id, nick)
);

CREATE TRIGGER IF NOT EXISTS keepers_exists
    BEFORE INSERT ON Keepers
    WHEN EXISTS (SELECT id FROM Keepers WHERE id = NEW.id AND nick = NEW.nick)
BEGIN
    UPDATE Keepers
    SET posted = NEW.posted
    WHERE id = NEW.id
      AND nick = NEW.nick;
    SELECT RAISE(IGNORE);
END;

INSERT INTO Keepers (id, nick) SELECT topic_id, nick FROM KeepersTemp;

DROP TABLE KeepersTemp;


-- Время обновления сведений
DROP TABLE IF EXISTS Other;

CREATE TABLE IF NOT EXISTS UpdateTime
(
    id INTEGER PRIMARY KEY NOT NULL,
    ud INTEGER
);

CREATE TRIGGER IF NOT EXISTS updatetime_exists
    BEFORE INSERT ON UpdateTime
    WHEN EXISTS (SELECT id FROM UpdateTime WHERE id = NEW.id)
BEGIN
    UPDATE UpdateTime
    SET ud = NEW.ud
    WHERE id = NEW.id;
    SELECT RAISE(IGNORE);
END;

CREATE TRIGGER IF NOT EXISTS updatetime_delete
    AFTER DELETE ON Forums FOR EACH ROW
BEGIN
    DELETE FROM UpdateTime WHERE id = OLD.id;
END;


-- Данные от торрент-клиентов
CREATE TABLE IF NOT EXISTS Clients
(
    hs VARCHAR NOT NULL,
    cl INTEGER NOT NULL,
    dl INTEGER,
    PRIMARY KEY (hs, cl)
);

CREATE TRIGGER IF NOT EXISTS clients_exists
    BEFORE INSERT ON Clients
    WHEN EXISTS (SELECT hs FROM Clients WHERE hs = NEW.hs AND cl = NEW.cl)
BEGIN
    UPDATE Clients
    SET dl = NEW.dl
    WHERE hs = NEW.hs
      AND cl = NEW.cl;
    SELECT RAISE(IGNORE);
END;

CREATE TRIGGER IF NOT EXISTS untracked_delete
    AFTER DELETE ON Clients FOR EACH ROW
BEGIN
    DELETE FROM TopicsUntracked WHERE hs = OLD.hs;
END;

INSERT INTO Clients (hs,cl,dl) SELECT hs,cl,dl FROM Topics;


-- Хранимые неотслеживаемые раздачи
CREATE TABLE IF NOT EXISTS TopicsUntracked
(
    id INT PRIMARY KEY NOT NULL,
    ss INT,
    na VARCHAR,
    hs VARCHAR,
    se INT,
    si INT,
    st INT,
    rg INT
);

CREATE TRIGGER IF NOT EXISTS untracked_exists
    BEFORE INSERT ON TopicsUntracked
    WHEN EXISTS (SELECT id FROM TopicsUntracked WHERE id = NEW.id)
BEGIN
    UPDATE TopicsUntracked
    SET ss = CASE WHEN NEW.ss IS NULL THEN ss ELSE NEW.ss END,
        na = CASE WHEN NEW.na IS NULL THEN na ELSE NEW.na END,
        hs = CASE WHEN NEW.hs IS NULL THEN hs ELSE NEW.hs END,
        se = CASE WHEN NEW.se IS NULL THEN se ELSE NEW.se END,
        si = CASE WHEN NEW.si IS NULL THEN si ELSE NEW.si END,
        st = CASE WHEN NEW.st IS NULL THEN st ELSE NEW.st END,
        rg = CASE WHEN NEW.rg IS NULL THEN rg ELSE NEW.rg END
    WHERE id = NEW.id;
    SELECT RAISE(IGNORE);
END;

INSERT INTO TopicsUntracked (id,ss,na,hs,se,si,st,rg)
SELECT id,ss,na,hs,se,si,st,rg
FROM Topics
WHERE dl = -2;

DELETE FROM Topics WHERE dl = -2;

-- Меняем структуру таблицы Blacklist
ALTER TABLE Blacklist RENAME TO BlacklistTemp;

CREATE TABLE IF NOT EXISTS Blacklist
(
    id      INTEGER PRIMARY KEY NOT NULL,
    comment VARCHAR
);

CREATE TRIGGER IF NOT EXISTS blacklist_exists
    BEFORE INSERT ON Blacklist
    WHEN EXISTS (SELECT id FROM Blacklist WHERE id = NEW.id)
BEGIN
    UPDATE Blacklist
    SET comment = NEW.comment
    WHERE id = NEW.id;
    SELECT RAISE(IGNORE);
END;

INSERT INTO Blacklist (id) SELECT topic_id FROM BlacklistTemp;

DROP TABLE BlacklistTemp;

-- Меняем структуру таблицы Topics
DROP TRIGGER IF EXISTS delete_topics;

ALTER TABLE Topics RENAME TO TopicsTemp;

CREATE TABLE IF NOT EXISTS Topics
(
    id INT PRIMARY KEY NOT NULL,
    ss INT,
    na VARCHAR,
    hs VARCHAR,
    se INT,
    si INT,
    st INT,
    rg INT,
    qt INT,
    ds INT
);

INSERT INTO Topics (id,ss,na,hs,se,si,st,rg,qt,ds)
SELECT id,ss,na,hs,se,si,st,rg,qt,ds
FROM TopicsTemp;

DROP TABLE TopicsTemp;

CREATE TRIGGER IF NOT EXISTS topic_exists
    BEFORE INSERT ON Topics
    WHEN EXISTS (SELECT id FROM Topics WHERE id = NEW.id)
BEGIN
    UPDATE Topics
    SET ss = CASE WHEN NEW.ss IS NULL THEN ss ELSE NEW.ss END,
        na = CASE WHEN NEW.na IS NULL THEN na ELSE NEW.na END,
        hs = CASE WHEN NEW.hs IS NULL THEN hs ELSE NEW.hs END,
        se = CASE WHEN NEW.se IS NULL THEN se ELSE NEW.se END,
        si = CASE WHEN NEW.si IS NULL THEN si ELSE NEW.si END,
        st = CASE WHEN NEW.st IS NULL THEN st ELSE NEW.st END,
        rg = CASE WHEN NEW.rg IS NULL THEN rg ELSE NEW.rg END,
        qt = CASE WHEN NEW.qt IS NULL THEN qt ELSE NEW.qt END,
        ds = CASE WHEN NEW.ds IS NULL THEN ds ELSE NEW.ds END
    WHERE id = NEW.id;
    SELECT RAISE(IGNORE);
END;

CREATE TRIGGER IF NOT EXISTS seeders_insert
    AFTER INSERT ON Topics
BEGIN
    INSERT INTO Seeders (id) VALUES (NEW.id);
END;

CREATE TRIGGER IF NOT EXISTS seeders_transfer
    AFTER UPDATE ON Topics WHEN NEW.ds <> OLD.ds
BEGIN
    UPDATE Seeders
    SET d0  = OLD.se,
        d1  = d0,
        d2  = d1,
        d3  = d2,
        d4  = d3,
        d5  = d4,
        d6  = d5,
        d7  = d6,
        d8  = d7,
        d9  = d8,
        d10 = d9,
        d11 = d10,
        d12 = d11,
        d13 = d12,
        d14 = d13,
        d15 = d14,
        d16 = d15,
        d17 = d16,
        d18 = d17,
        d19 = d18,
        d20 = d19,
        d21 = d20,
        d22 = d21,
        d23 = d22,
        d24 = d23,
        d25 = d24,
        d26 = d25,
        d27 = d26,
        d28 = d27,
        d29 = d28,
        q0  = OLD.qt,
        q1  = q0,
        q2  = q1,
        q3  = q2,
        q4  = q3,
        q5  = q4,
        q6  = q5,
        q7  = q6,
        q8  = q7,
        q9  = q8,
        q10 = q9,
        q11 = q10,
        q12 = q11,
        q13 = q12,
        q14 = q13,
        q15 = q14,
        q16 = q15,
        q17 = q16,
        q18 = q17,
        q19 = q18,
        q20 = q19,
        q21 = q20,
        q22 = q21,
        q23 = q22,
        q24 = q23,
        q25 = q24,
        q26 = q25,
        q27 = q26,
        q28 = q27,
        q29 = q28
    WHERE id = NEW.id;
END;

CREATE TRIGGER IF NOT EXISTS topics_delete
    AFTER DELETE ON Topics FOR EACH ROW
BEGIN
    DELETE FROM Seeders WHERE id = OLD.id;
    DELETE FROM Blacklist WHERE id = OLD.id;
END;

-- Триггер для обновления данных о подразделах
DROP TRIGGER IF EXISTS Forums_update;

CREATE TRIGGER IF NOT EXISTS forums_exists
    BEFORE INSERT ON Forums
    WHEN EXISTS (SELECT id FROM Forums WHERE id = NEW.id)
BEGIN
    UPDATE Forums
    SET na = NEW.na,
        qt = NEW.qt,
        si = NEW.si
    WHERE id = NEW.id;
    SELECT RAISE(IGNORE);
END;

PRAGMA user_version = 4;