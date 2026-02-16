CREATE TABLE IF NOT EXISTS KeepersSeeders
(
    topic_id INTEGER NOT NULL,
    nick     VARCHAR NOT NULL,
    PRIMARY KEY (topic_id, nick)
);

CREATE TRIGGER IF NOT EXISTS keepers_seeders_exists
    BEFORE INSERT ON KeepersSeeders
    WHEN EXISTS (SELECT topic_id FROM KeepersSeeders WHERE topic_id = NEW.topic_id AND nick = NEW.nick)
BEGIN
    SELECT RAISE(IGNORE);
END;

PRAGMA user_version = 7;