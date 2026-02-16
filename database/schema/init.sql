-- Создадим таблицу списка подразделов.
CREATE TABLE IF NOT EXISTS Forums
(
    id       INT     NOT NULL PRIMARY KEY,
    name     VARCHAR NOT NULL,
    quantity INT,
    size     INT
);

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

-- Список хранителей по спискам.
CREATE TABLE IF NOT EXISTS KeepersLists
(
    topic_id    INT NOT NULL,
    keeper_id   INT NOT NULL,
    keeper_name VARCHAR,
    posted      INT,
    complete    INT DEFAULT 1,
    PRIMARY KEY (topic_id, keeper_id)
);

CREATE TRIGGER IF NOT EXISTS keepers_lists_exists
    BEFORE INSERT ON KeepersLists
    WHEN EXISTS (SELECT topic_id FROM KeepersLists WHERE topic_id = NEW.topic_id AND keeper_id = NEW.keeper_id)
BEGIN
    UPDATE KeepersLists
    SET keeper_name = NEW.keeper_name,
        posted      = NEW.posted,
        complete    = NEW.complete
    WHERE topic_id = NEW.topic_id
      AND keeper_id = NEW.keeper_id;
    SELECT RAISE(IGNORE);
END;

-- Список сидов-хранителей.
CREATE TABLE IF NOT EXISTS KeepersSeeders
(
    topic_id    INT NOT NULL,
    keeper_id   INT NOT NULL,
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

-- Данные раздач по данным форума.
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

-- Исторические данные по средним сидам.
CREATE TABLE IF NOT EXISTS Seeders
(
    id  INT NOT NULL PRIMARY KEY,
    d0  INT,
    d1  INT,
    d2  INT,
    d3  INT,
    d4  INT,
    d5  INT,
    d6  INT,
    d7  INT,
    d8  INT,
    d9  INT,
    d10 INT,
    d11 INT,
    d12 INT,
    d13 INT,
    d14 INT,
    d15 INT,
    d16 INT,
    d17 INT,
    d18 INT,
    d19 INT,
    d20 INT,
    d21 INT,
    d22 INT,
    d23 INT,
    d24 INT,
    d25 INT,
    d26 INT,
    d27 INT,
    d28 INT,
    d29 INT,
    q0  INT,
    q1  INT,
    q2  INT,
    q3  INT,
    q4  INT,
    q5  INT,
    q6  INT,
    q7  INT,
    q8  INT,
    q9  INT,
    q10 INT,
    q11 INT,
    q12 INT,
    q13 INT,
    q14 INT,
    q15 INT,
    q16 INT,
    q17 INT,
    q18 INT,
    q19 INT,
    q20 INT,
    q21 INT,
    q22 INT,
    q23 INT,
    q24 INT,
    q25 INT,
    q26 INT,
    q27 INT,
    q28 INT,
    q29 INT
);

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

-- Добавляем триггер для возможности полного обновления истории сидов.
CREATE TRIGGER IF NOT EXISTS seeders_exists
    BEFORE INSERT ON Seeders
    FOR EACH ROW
    WHEN EXISTS (SELECT 1 FROM Seeders WHERE id = NEW.id)
BEGIN
    UPDATE Seeders
    SET d0  = NEW.d0,
        d1  = NEW.d1,
        d2  = NEW.d2,
        d3  = NEW.d3,
        d4  = NEW.d4,
        d5  = NEW.d5,
        d6  = NEW.d6,
        d7  = NEW.d7,
        d8  = NEW.d8,
        d9  = NEW.d9,
        d10 = NEW.d10,
        d11 = NEW.d11,
        d12 = NEW.d12,
        d13 = NEW.d13,
        d14 = NEW.d14,
        d15 = NEW.d15,
        d16 = NEW.d16,
        d17 = NEW.d17,
        d18 = NEW.d18,
        d19 = NEW.d19,
        d20 = NEW.d20,
        d21 = NEW.d21,
        d22 = NEW.d22,
        d23 = NEW.d23,
        d24 = NEW.d24,
        d25 = NEW.d25,
        d26 = NEW.d26,
        d27 = NEW.d27,
        d28 = NEW.d28,
        d29 = NEW.d29,
        q0  = NEW.q0,
        q1  = NEW.q1,
        q2  = NEW.q2,
        q3  = NEW.q3,
        q4  = NEW.q4,
        q5  = NEW.q5,
        q6  = NEW.q6,
        q7  = NEW.q7,
        q8  = NEW.q8,
        q9  = NEW.q9,
        q10 = NEW.q10,
        q11 = NEW.q11,
        q12 = NEW.q12,
        q13 = NEW.q13,
        q14 = NEW.q14,
        q15 = NEW.q15,
        q16 = NEW.q16,
        q17 = NEW.q17,
        q18 = NEW.q18,
        q19 = NEW.q19,
        q20 = NEW.q20,
        q21 = NEW.q21,
        q22 = NEW.q22,
        q23 = NEW.q23,
        q24 = NEW.q24,
        q25 = NEW.q25,
        q26 = NEW.q26,
        q27 = NEW.q27,
        q28 = NEW.q28,
        q29 = NEW.q29
    WHERE id = NEW.id;

    -- Prevent the actual insert
    SELECT RAISE(IGNORE);
END;


-- Исключённые раздачи, чёрный список.
CREATE TABLE IF NOT EXISTS TopicsExcluded
(
    info_hash  TEXT PRIMARY KEY ON CONFLICT REPLACE NOT NULL,
    time_added INT DEFAULT (strftime('%s')),
    comment    TEXT
);

-- Разрегистрированные раздачи, по данным форума.
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

-- Неотслеживаемые раздачи, из нехранимых подразделов.
CREATE TABLE IF NOT EXISTS TopicsUntracked
(
    id        INT PRIMARY KEY NOT NULL,
    forum_id  INT,
    name      VARCHAR,
    info_hash VARCHAR,
    seeders   INT,
    size      INT,
    status    INT,
    reg_time  INT
);

CREATE TRIGGER IF NOT EXISTS untracked_exists
    BEFORE INSERT ON TopicsUntracked
    WHEN EXISTS (SELECT id FROM TopicsUntracked WHERE id = NEW.id)
BEGIN
    UPDATE TopicsUntracked
    SET forum_id = CASE WHEN NEW.forum_id  IS NULL THEN forum_id  ELSE NEW.forum_id END,
        name     = CASE WHEN NEW.name      IS NULL THEN name      ELSE NEW.name END,
        info_hash= CASE WHEN NEW.info_hash IS NULL THEN info_hash ELSE NEW.info_hash END,
        seeders  = CASE WHEN NEW.seeders   IS NULL THEN seeders   ELSE NEW.seeders END,
        size     = CASE WHEN NEW.size      IS NULL THEN size      ELSE NEW.size END,
        status   = CASE WHEN NEW.status    IS NULL THEN status    ELSE NEW.status END,
        reg_time = CASE WHEN NEW.reg_time  IS NULL THEN reg_time  ELSE NEW.reg_time END
    WHERE id = NEW.id;
    SELECT RAISE(IGNORE);
END;

-- Хранимые раздачи, по данным торрент-клиентов.
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

CREATE INDEX IF NOT EXISTS IX_Torrents_error ON Torrents (error);

CREATE TRIGGER IF NOT EXISTS remove_unregistered_topics
    AFTER DELETE ON Torrents FOR EACH ROW
BEGIN
    DELETE FROM TopicsUnregistered WHERE info_hash = OLD.info_hash;
END;

CREATE TRIGGER IF NOT EXISTS remove_untracked_topics
    AFTER DELETE ON Torrents FOR EACH ROW
BEGIN
    DELETE FROM TopicsUntracked WHERE info_hash = OLD.info_hash;
END;

-- Время обновления сведений.
CREATE TABLE IF NOT EXISTS UpdateTime
(
    id INT PRIMARY KEY NOT NULL,
    ud INT
);

CREATE TRIGGER IF NOT EXISTS updatetime_exists
    BEFORE INSERT ON UpdateTime
    WHEN EXISTS (SELECT id FROM UpdateTime WHERE id = NEW.id)
BEGIN
    UPDATE UpdateTime
    SET ud = NEW.ud
    WHERE id = NEW.id;
    SELECT RAISE(IGNORE);
END;

CREATE TRIGGER IF NOT EXISTS updatetime_delete
    AFTER DELETE ON Forums FOR EACH ROW
BEGIN
    DELETE FROM UpdateTime WHERE id = OLD.id;
END;

-- Запишем текущую версию БД.
PRAGMA user_version = 15;
