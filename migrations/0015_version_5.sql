--
-- user_version = 5
--

ALTER TABLE Topics
    ADD COLUMN pt INT DEFAULT 1;
DROP TRIGGER IF EXISTS topic_exists;

CREATE TRIGGER IF NOT EXISTS topic_exists
    BEFORE INSERT
    ON Topics
    WHEN EXISTS(
            SELECT id
            FROM Topics
            WHERE id = NEW.id
        )
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
        ds = CASE WHEN NEW.ds IS NULL THEN ds ELSE NEW.ds END,
        pt = CASE WHEN NEW.pt IS NULL THEN pt ELSE NEW.pt END
    WHERE id = NEW.id;
    SELECT RAISE(IGNORE);
END;

PRAGMA user_version = 5;
