DROP TRIGGER IF EXISTS Seeders_update;

ALTER TABLE Forums ADD COLUMN qt INT;
ALTER TABLE Forums ADD COLUMN si INT;
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
    dl INT,
    qt INT,
    ds INT,
    cl VARCHAR
);

INSERT INTO Topics (id,ss,na,hs,se,si,st,rg,dl,qt,ds,cl)
SELECT id,ss,na,hs,se,si,st,rg,dl,rt,ds,cl FROM TopicsTemp;

DROP TABLE TopicsTemp;

CREATE TRIGGER IF NOT EXISTS delete_seeders
    AFTER DELETE ON Topics FOR EACH ROW
BEGIN
    DELETE FROM Seeders WHERE id = OLD.id;
END;

CREATE TRIGGER IF NOT EXISTS insert_seeders
    AFTER INSERT ON Topics
BEGIN
    INSERT INTO Seeders (id) VALUES (NEW.id);
END;

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
        dl = CASE WHEN NEW.dl IS NULL THEN dl ELSE NEW.dl END,
        qt = CASE WHEN NEW.qt IS NULL THEN qt ELSE NEW.qt END,
        ds = CASE WHEN NEW.ds IS NULL THEN ds ELSE NEW.ds END,
        cl = CASE WHEN NEW.cl IS NULL THEN cl ELSE NEW.cl END
    WHERE id = NEW.id;
    SELECT RAISE(IGNORE);
END;

CREATE TRIGGER IF NOT EXISTS transfer_seeders
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

PRAGMA user_version = 2;