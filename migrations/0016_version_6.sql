--
-- user_version = 6
--

DROP TRIGGER IF EXISTS keepers_exists;
ALTER TABLE Keepers
    ADD COLUMN complete INT DEFAULT 1;

CREATE TRIGGER IF NOT EXISTS keepers_exists
    BEFORE INSERT
    ON Keepers
    WHEN EXISTS(
            SELECT id
            FROM Keepers
            WHERE id = NEW.id
              AND nick = NEW.nick
        )
BEGIN
    UPDATE Keepers
    SET posted   = NEW.posted,
        complete = NEW.complete
    WHERE id = NEW.id
      AND nick = NEW.nick;
    SELECT RAISE(IGNORE);
END;

PRAGMA user_version = 6;
