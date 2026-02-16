-- Переименуем названия ячеек таблицы TopicsUntracked.
ALTER TABLE TopicsUntracked RENAME COLUMN ss TO forum_id;
ALTER TABLE TopicsUntracked RENAME COLUMN na TO name;
ALTER TABLE TopicsUntracked RENAME COLUMN hs TO info_hash;
ALTER TABLE TopicsUntracked RENAME COLUMN se TO seeders;
ALTER TABLE TopicsUntracked RENAME COLUMN si TO size;
ALTER TABLE TopicsUntracked RENAME COLUMN st TO status;
ALTER TABLE TopicsUntracked RENAME COLUMN rg TO reg_time;


-- Удаляем существующие триггеры по таблице Topics.
DROP TRIGGER IF EXISTS topic_exists;
DROP TRIGGER IF EXISTS topics_delete;
DROP TRIGGER IF EXISTS seeders_insert;
DROP TRIGGER IF EXISTS seeders_transfer;

-- Создаём таблицу заново и переносим данные.
ALTER TABLE Topics RENAME TO Topics_old;

CREATE TABLE IF NOT EXISTS Topics
(
    id                    INT PRIMARY KEY NOT NULL,
    forum_id              INT,      -- Ид Подраздела
    name                  VARCHAR,  -- Название раздачи
    info_hash             VARCHAR,  -- Хеш раздачи
    seeders               INT,      -- Симмарное количество сидов за сегодня
    size                  INT,      -- Размер раздачи (байт)
    status                INT,      -- Статус раздачи на форуме
    reg_time              INT,      -- Дата регистрации
    seeders_updates_today INT,      -- Количество обновлений за сегодня
    seeders_updates_days  INT,      -- Количество дней обновлений
    keeping_priority      INT,      -- Приоритет хранения
    poster                INT DEFAULT 0,    -- Автор раздачи
    seeder_last_seen      INT DEFAULT 0     -- Последняя доступность сида
);

CREATE INDEX IF NOT EXISTS IX_Topics_forum_hash ON Topics (forum_id, info_hash);

INSERT INTO Topics SELECT * FROM Topics_old;

-- Удаляем старую таблицу.
DROP TABLE Topics_old;

-- Создаём триггеры заново.
CREATE TRIGGER IF NOT EXISTS topic_exists
    BEFORE INSERT ON Topics
    WHEN EXISTS (SELECT id FROM Topics WHERE id = NEW.id)
BEGIN
    UPDATE Topics
    SET forum_id              = COALESCE(NEW.forum_id, forum_id),
        name                  = COALESCE(NEW.name, name),
        info_hash             = COALESCE(NEW.info_hash, info_hash),
        seeders               = COALESCE(NEW.seeders, seeders),
        size                  = COALESCE(NEW.size, size),
        status                = COALESCE(NEW.status, status),
        reg_time              = COALESCE(NEW.reg_time, reg_time),
        seeders_updates_today = COALESCE(NEW.seeders_updates_today, seeders_updates_today),
        seeders_updates_days  = COALESCE(NEW.seeders_updates_days, seeders_updates_days),
        keeping_priority      = COALESCE(NEW.keeping_priority, keeping_priority),
        poster                = CASE WHEN NEW.poster = 0 THEN poster ELSE NEW.poster END,
        seeder_last_seen      = MAX(NEW.seeder_last_seen, seeder_last_seen)
    WHERE id = NEW.id;
    SELECT RAISE(IGNORE);
END;


CREATE TRIGGER IF NOT EXISTS topic_delete
    AFTER DELETE ON Topics FOR EACH ROW
BEGIN
    DELETE FROM Seeders WHERE id = OLD.id;
END;


CREATE TRIGGER IF NOT EXISTS seeders_insert
    AFTER INSERT ON Topics
BEGIN
    INSERT INTO Seeders (id) VALUES (NEW.id);
END;


CREATE TRIGGER IF NOT EXISTS seeders_transfer
    AFTER UPDATE ON Topics WHEN NEW.seeders_updates_days <> OLD.seeders_updates_days
BEGIN
    UPDATE Seeders
    SET d0  = OLD.seeders,
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
        q0  = OLD.seeders_updates_today,
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

-- Обновляем версию БД.
PRAGMA user_version = 13;