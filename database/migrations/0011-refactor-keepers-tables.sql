-- Пересоздадим таблицу раздач других хранителей.
DROP TABLE IF EXISTS Keepers;

DROP TRIGGER IF EXISTS keepers_exists;

CREATE TABLE IF NOT EXISTS KeepersLists
(
    topic_id    INTEGER NOT NULL,
    keeper_id   INTEGER NOT NULL,
    keeper_name VARCHAR,
    posted      INTEGER,
    complete    INT DEFAULT 1,
    PRIMARY KEY (topic_id, keeper_id)
);

CREATE TRIGGER IF NOT EXISTS keepers_lists_exists
    BEFORE INSERT ON KeepersLists
    WHEN EXISTS (SELECT topic_id FROM KeepersLists WHERE topic_id = NEW.topic_id AND keeper_id = NEW.keeper_id)
BEGIN
    UPDATE KeepersLists SET
                            keeper_name = NEW.keeper_name,
                            posted      = NEW.posted,
                            complete    = NEW.complete
    WHERE topic_id = NEW.topic_id AND keeper_id = NEW.keeper_id;
    SELECT RAISE(IGNORE);
END;


-- Список сидов-хранителей.
DROP TABLE IF EXISTS KeepersSeeders;

DROP TRIGGER IF EXISTS keepers_seeders_exists;

CREATE TABLE IF NOT EXISTS KeepersSeeders
(
    topic_id    INTEGER NOT NULL,
    keeper_id   INTEGER NOT NULL,
    keeper_name VARCHAR,
    PRIMARY KEY (topic_id, keeper_id)
);

CREATE TRIGGER IF NOT EXISTS keepers_seeders_exists
    BEFORE INSERT ON KeepersSeeders
    WHEN EXISTS (SELECT topic_id FROM KeepersSeeders WHERE topic_id = NEW.topic_id AND keeper_id = NEW.keeper_id)
BEGIN
    UPDATE KeepersSeeders SET
        keeper_name = NEW.keeper_name
    WHERE topic_id = NEW.topic_id AND keeper_id = NEW.keeper_id;
    SELECT RAISE(IGNORE);
END;

PRAGMA user_version = 11;