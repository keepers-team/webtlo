--
-- user_version = 3
--

CREATE TABLE IF NOT EXISTS Blacklist
(
    id       INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
    topic_id INTEGER                           NOT NULL
);

DROP TRIGGER IF EXISTS delete_seeders;

CREATE TRIGGER IF NOT EXISTS delete_topics
    AFTER DELETE
    ON Topics
    FOR EACH ROW
BEGIN
    DELETE FROM Seeders WHERE id = OLD.id;
    DELETE
    FROM Blacklist
    WHERE topic_id = OLD.id;
END;

PRAGMA user_version = 3;
