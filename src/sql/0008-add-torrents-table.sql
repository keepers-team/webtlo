-- Создаём новую таблицу для данных торрент-клиентов
CREATE TABLE IF NOT EXISTS Torrents
(
    info_hash     TEXT NOT NULL,
    client_id     INT  NOT NULL,
    topic_id      INT,
    name          TEXT,
    total_size    INT,
    paused        BOOLEAN DEFAULT (0),
    done          REAL    DEFAULT (0),
    time_added    INT     DEFAULT (strftime('%s')),
    error         BOOLEAN DEFAULT (0),
    tracker_error TEXT,
    PRIMARY KEY (
                 info_hash,
                 client_id
        )
        ON CONFLICT REPLACE
);

-- Переносим существующие данные из старой таблицы
INSERT INTO Torrents (info_hash, client_id, topic_id, name, total_size, paused, done, time_added, error)
SELECT c.hs info_hash, c.cl client_id, t.id topic_id, t.na name, t.si total_size
     ,CASE WHEN c.dl = -1 THEN 1 ELSE 0 END paused
     ,CASE WHEN c.dl != 0 THEN 1 ELSE 0 END done
     ,t.rg time_added, 0 error
FROM Clients c
    INNER JOIN Topics t ON t.hs = c.hs;

DROP TABLE IF EXISTS Clients;

CREATE TRIGGER IF NOT EXISTS remove_untracked_topics
    AFTER DELETE ON Torrents FOR EACH ROW
BEGIN
    DELETE FROM TopicsUntracked WHERE hs = OLD.info_hash;
END;

PRAGMA user_version = 8;