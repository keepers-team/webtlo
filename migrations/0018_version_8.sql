--
-- user_version = 8
--

DROP TABLE IF EXISTS Clients;

CREATE TABLE IF NOT EXISTS Torrents
(
    info_hash     TEXT NOT NULL,
    client_id     INT  NOT NULL,
    topic_id      INT,
    name          TEXT,
    total_size    INT,
    paused        BOOLEAN DEFAULT (0),
    done          REAL    DEFAULT (0),
    time_added    INT     default (strftime('%s')),
    error         BOOLEAN DEFAULT (0),
    tracker_error TEXT,
    PRIMARY KEY (
                 info_hash,
                 client_id
        )
        ON CONFLICT REPLACE
);

CREATE TRIGGER IF NOT EXISTS remove_untracked_topics
    AFTER DELETE
    ON Torrents
    FOR EACH ROW
BEGIN
    DELETE FROM TopicsUntracked WHERE hs = OLD.info_hash;
END;

PRAGMA user_version = 8;

