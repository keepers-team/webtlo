-- Изменим таблицу списка подразделов.
ALTER TABLE Forums RENAME COLUMN na TO name;
ALTER TABLE Forums RENAME COLUMN qt TO quantity;
ALTER TABLE Forums RENAME COLUMN si TO "size";

DROP TRIGGER IF EXISTS forums_exists;

CREATE TRIGGER IF NOT EXISTS forums_exists
    BEFORE INSERT ON Forums
    WHEN EXISTS (SELECT id FROM Forums WHERE id = NEW.id)
BEGIN
    UPDATE Forums
    SET name     = NEW.name,
        quantity = NEW.quantity,
        size     = NEW.size
    WHERE id = NEW.id;
    SELECT RAISE(IGNORE);
END;


-- Создадим таблицу дополнительных сведений о хранимых подразделах.
DROP TABLE IF EXISTS ForumsOptions;
DROP TRIGGER IF EXISTS forums_options_exists;

CREATE TABLE IF NOT EXISTS ForumsOptions
(
    forum_id       INT PRIMARY KEY,
    topic_id       INT,
    author_id      INT,
    author_name    VARCHAR,
    author_post_id INT,
    post_ids       JSON
);

CREATE TRIGGER IF NOT EXISTS forums_options_exists
    BEFORE INSERT ON ForumsOptions
    WHEN EXISTS (SELECT forum_id FROM ForumsOptions WHERE forum_id = NEW.forum_id)
BEGIN
    UPDATE ForumsOptions
    SET
        topic_id       = CASE WHEN NEW.topic_id       IS NULL THEN topic_id       ELSE NEW.topic_id END,
        author_id      = CASE WHEN NEW.author_id      IS NULL THEN author_id      ELSE NEW.author_id END,
        author_name    = CASE WHEN NEW.author_name    IS NULL THEN author_name    ELSE NEW.author_name END,
        author_post_id = CASE WHEN NEW.author_post_id IS NULL THEN author_post_id ELSE NEW.author_post_id END,
        post_ids       = CASE WHEN NEW.post_ids       IS NULL THEN post_ids       ELSE NEW.post_ids END
    WHERE forum_id = NEW.forum_id;
    SELECT RAISE(IGNORE);
END;


-- Удалим признак обновления дерева подразделов.
DELETE FROM UpdateTime WHERE id = 8888;

-- Удалим признакми обновления списков других хранителей, для обновления параметров подразделов.
DELETE FROM UpdateTime WHERE id BETWEEN 100000 AND 200000;


CREATE INDEX IF NOT EXISTS IX_Torrents_error ON Torrents (error);

PRAGMA user_version = 12;