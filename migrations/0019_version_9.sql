--
-- user_version = 9
--

CREATE TABLE IF NOT EXISTS TopicsUnregistered
(
    info_hash           TEXT PRIMARY KEY ON CONFLICT REPLACE NOT NULL,
    name                TEXT,
    status              TEXT                                 NOT NULL,
    priority            TEXT,
    transferred_from    TEXT,
    transferred_to      TEXT,
    transferred_by_whom TEXT
);

CREATE TRIGGER IF NOT EXISTS remove_unregistered_topics
    AFTER DELETE
    ON Torrents
    FOR EACH ROW
BEGIN
    DELETE FROM TopicsUnregistered WHERE info_hash = OLD.info_hash;
END;

CREATE TABLE IF NOT EXISTS TopicsExcluded
(
    info_hash  TEXT PRIMARY KEY ON CONFLICT REPLACE NOT NULL,
    time_added INT DEFAULT (strftime('%s')),
    comment    TEXT
);

INSERT INTO TopicsExcluded (info_hash, comment)
SELECT Topics.hs, Blacklist.comment
FROM Blacklist
         LEFT JOIN Topics ON Topics.id = Blacklist.id
WHERE Blacklist.id IS NOT NULL;

DROP TABLE IF EXISTS Blacklist;
DROP TRIGGER IF EXISTS topics_delete;

CREATE TRIGGER IF NOT EXISTS topics_delete
    AFTER DELETE
    ON Topics
    FOR EACH ROW
BEGIN
    DELETE FROM Seeders WHERE id = OLD.id;
END;

PRAGMA user_version = 9;
