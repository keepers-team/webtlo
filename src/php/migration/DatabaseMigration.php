<?php

/**
 * Описание миграции данных при ихменении версии БД.
 */
class DatabaseMigration
{
    private array $statements = [];

    /**
     * Совместимость со старыми версиями базы данных
     * @param int $version Текущая версия БД
     * @param int $pragmaVersion Актуальная версия БД
     */
    public function getStatements(int $version, int $pragmaVersion): array
    {
        // Повышаем версию по одной, собирая нужные запросы.
        while ($version < $pragmaVersion) {
            $version++;
            $method = "setPragmaVersion_$version";
            if (method_exists($this, $method)) {
                $this->$method();
            }
        }

        return $this->statements;
    }

    // user_version = 0
    private function setPragmaVersion_0(): void
    {
        // Оставил запросы для истории. Фактически не нужно.

        // список подразделов
        $this->statements[] = [
            'CREATE TABLE IF NOT EXISTS Forums (',
            '    id INT NOT NULL PRIMARY KEY,',
            '    na VARCHAR NOT NULL',
            ');'
        ];
        // список раздач
        $this->statements[] = [
            'CREATE TABLE IF NOT EXISTS Topics (',
            '    id INT NOT NULL PRIMARY KEY,',
            '    ss INT NOT NULL,',
            '    na VARCHAR NOT NULL,',
            '    hs VARCHAR NOT NULL,',
            '    se INT NOT NULL,',
            '    si INT NOT NULL,',
            '    st INT NOT NULL,',
            '    rg INT NOT NULL,',
            '    dl INT NOT NULL DEFAULT 0',
            ');'
        ];
        // средние сиды
        $this->statements[] = [
            'CREATE TABLE IF NOT EXISTS Seeders (',
            '    id INT NOT NULL PRIMARY KEY,',
            '    d0 INT,',
            '    d1 INT,',
            '    d2 INT,',
            '    d3 INT,',
            '    d4 INT,',
            '    d5 INT,',
            '    d6 INT,',
            '    d7 INT,',
            '    d8 INT,',
            '    d9 INT,',
            '    d10 INT,',
            '    d11 INT,',
            '    d12 INT,',
            '    d13 INT,',
            '    d14 INT,',
            '    d15 INT,',
            '    d16 INT,',
            '    d17 INT,',
            '    d18 INT,',
            '    d19 INT,',
            '    d20 INT,',
            '    d21 INT,',
            '    d22 INT,',
            '    d23 INT,',
            '    d24 INT,',
            '    d25 INT,',
            '    d26 INT,',
            '    d27 INT,',
            '    d28 INT,',
            '    d29 INT,',
            '    q0 INT, ',
            '    q1 INT,',
            '    q2 INT,',
            '    q3 INT,',
            '    q4 INT,',
            '    q5 INT,',
            '    q6 INT,',
            '    q7 INT,',
            '    q8 INT,',
            '    q9 INT,',
            '    q10 INT,',
            '    q11 INT,',
            '    q12 INT,',
            '    q13 INT,',
            '    q14 INT,',
            '    q15 INT,',
            '    q16 INT,',
            '    q17 INT,',
            '    q18 INT,',
            '    q19 INT,',
            '    q20 INT,',
            '    q21 INT,',
            '    q22 INT,',
            '    q23 INT,',
            '    q24 INT,',
            '    q25 INT,',
            '    q26 INT,',
            '    q27 INT,',
            '    q28 INT,',
            '    q29 INT',
            ');'
        ];
        // список хранимого другими
        $this->statements[] = [
            'CREATE TABLE IF NOT EXISTS Keepers (',
            '    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,',
            '    topic_id INTEGER NOT NULL,',
            '    nick VARCHAR NOT NULL',
            ');'
        ];
    }

    // user_version = 1
    private function setPragmaVersion_1(): void
    {
        $this->statements[] = 'ALTER TABLE Topics ADD COLUMN rt INT DEFAULT 1';
        $this->statements[] = 'ALTER TABLE Topics ADD COLUMN ds INT DEFAULT 0';
        $this->statements[] = 'ALTER TABLE Topics ADD COLUMN cl VARCHAR';
        $this->statements[] = 'PRAGMA user_version = 1';
    }

    // user_version = 2
    private function setPragmaVersion_2(): void
    {
        $this->statements[] = 'DROP TRIGGER IF EXISTS Seeders_update';
        $this->statements[] = 'ALTER TABLE Forums ADD COLUMN qt INT';
        $this->statements[] = 'ALTER TABLE Forums ADD COLUMN si INT';
        $this->statements[] = 'ALTER TABLE Topics RENAME TO TopicsTemp';
        $this->statements[] = [
            'CREATE TABLE IF NOT EXISTS Topics (',
            '    id INT PRIMARY KEY NOT NULL,',
            '    ss INT,',
            '    na VARCHAR,',
            '    hs VARCHAR,',
            '    se INT,',
            '    si INT,',
            '    st INT,',
            '    rg INT,',
            '    dl INT,',
            '    qt INT,',
            '    ds INT,',
            '    cl VARCHAR',
            ');'
        ];
        $this->statements[] = [
            'INSERT INTO Topics (id,ss,na,hs,se,si,st,rg,dl,qt,ds,cl)',
            'SELECT id,ss,na,hs,se,si,st,rg,dl,rt,ds,cl FROM TopicsTemp'
        ];
        $this->statements[] = 'DROP TABLE TopicsTemp';
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS delete_seeders',
            'AFTER DELETE ON Topics FOR EACH ROW',
            'BEGIN',
            '    DELETE FROM Seeders WHERE id = OLD.id;',
            'END;'
        ];
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS insert_seeders',
            'AFTER INSERT ON Topics',
            'BEGIN',
            '    INSERT INTO Seeders (id) VALUES (NEW.id);',
            'END;'
        ];
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS topic_exists',
            'BEFORE INSERT ON Topics',
            'WHEN EXISTS (SELECT id FROM Topics WHERE id = NEW.id)',
            'BEGIN',
            '    UPDATE Topics SET',
            '        ss = CASE WHEN NEW.ss IS NULL THEN ss ELSE NEW.ss END,',
            '        na = CASE WHEN NEW.na IS NULL THEN na ELSE NEW.na END,',
            '        hs = CASE WHEN NEW.hs IS NULL THEN hs ELSE NEW.hs END,',
            '        se = CASE WHEN NEW.se IS NULL THEN se ELSE NEW.se END,',
            '        si = CASE WHEN NEW.si IS NULL THEN si ELSE NEW.si END,',
            '        st = CASE WHEN NEW.st IS NULL THEN st ELSE NEW.st END,',
            '        rg = CASE WHEN NEW.rg IS NULL THEN rg ELSE NEW.rg END,',
            '        dl = CASE WHEN NEW.dl IS NULL THEN dl ELSE NEW.dl END,',
            '        qt = CASE WHEN NEW.qt IS NULL THEN qt ELSE NEW.qt END,',
            '        ds = CASE WHEN NEW.ds IS NULL THEN ds ELSE NEW.ds END,',
            '        cl = CASE WHEN NEW.cl IS NULL THEN cl ELSE NEW.cl END',
            '    WHERE id = NEW.id;',
            '    SELECT RAISE(IGNORE);',
            'END;'
        ];
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS transfer_seeders',
            'AFTER UPDATE ON Topics WHEN NEW.ds <> OLD.ds',
            'BEGIN',
            '    UPDATE Seeders SET',
            '        d0 = OLD.se,',
            '        d1 = d0,',
            '        d2 = d1,',
            '        d3 = d2,',
            '        d4 = d3,',
            '        d5 = d4,',
            '        d6 = d5,',
            '        d7 = d6,',
            '        d8 = d7,',
            '        d9 = d8,',
            '        d10 = d9,',
            '        d11 = d10,',
            '        d12 = d11,',
            '        d13 = d12,',
            '        d14 = d13,',
            '        d15 = d14,',
            '        d16 = d15,',
            '        d17 = d16,',
            '        d18 = d17,',
            '        d19 = d18,',
            '        d20 = d19,',
            '        d21 = d20,',
            '        d22 = d21,',
            '        d23 = d22,',
            '        d24 = d23,',
            '        d25 = d24,',
            '        d26 = d25,',
            '        d27 = d26,',
            '        d28 = d27,',
            '        d29 = d28,',
            '        q0 = OLD.qt,',
            '        q1 = q0,',
            '        q2 = q1,',
            '        q3 = q2,',
            '        q4 = q3,',
            '        q5 = q4,',
            '        q6 = q5,',
            '        q7 = q6,',
            '        q8 = q7,',
            '        q9 = q8,',
            '        q10 = q9,',
            '        q11 = q10,',
            '        q12 = q11,',
            '        q13 = q12,',
            '        q14 = q13,',
            '        q15 = q14,',
            '        q16 = q15,',
            '        q17 = q16,',
            '        q18 = q17,',
            '        q19 = q18,',
            '        q20 = q19,',
            '        q21 = q20,',
            '        q22 = q21,',
            '        q23 = q22,',
            '        q24 = q23,',
            '        q25 = q24,',
            '        q26 = q25,',
            '        q27 = q26,',
            '        q28 = q27,',
            '        q29 = q28',
            '    WHERE id = NEW.id;',
            'END;'
        ];
        $this->statements[] = 'PRAGMA user_version = 2';
    }

    // user_version = 3
    private function setPragmaVersion_3(): void
    {
        $this->statements[] = [
            'CREATE TABLE IF NOT EXISTS Blacklist (',
            '    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,',
            '    topic_id INTEGER NOT NULL',
            ');'
        ];
        $this->statements[] = 'DROP TRIGGER delete_seeders';
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS delete_topics',
            'AFTER DELETE ON Topics FOR EACH ROW',
            'BEGIN',
            '    DELETE FROM Seeders WHERE id = OLD.id;',
            '    DELETE FROM Blacklist WHERE topic_id = OLD.id;',
            'END;'
        ];
        $this->statements[] = 'PRAGMA user_version = 3';
    }

    // user_version = 4
    private function setPragmaVersion_4(): void
    {
        // меняем структуру таблицы Keepers
        $this->statements[] = 'ALTER TABLE Keepers RENAME TO KeepersTemp';
        $this->statements[] = [
            'CREATE TABLE IF NOT EXISTS Keepers (',
            '    id INTEGER NOT NULL,',
            '    nick VARCHAR NOT NULL,',
            '    posted INTEGER,',
            '    PRIMARY KEY (id, nick)',
            ');'
        ];
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS keepers_exists',
            'BEFORE INSERT ON Keepers',
            'WHEN EXISTS (SELECT id FROM Keepers WHERE id = NEW.id AND nick = NEW.nick)',
            'BEGIN',
            '    UPDATE Keepers SET',
            '        posted = NEW.posted',
            '    WHERE id = NEW.id AND nick = NEW.nick;',
            '    SELECT RAISE(IGNORE);',
            'END;'
        ];
        $this->statements[] = 'INSERT INTO Keepers (id,nick) SELECT topic_id,nick FROM KeepersTemp';
        $this->statements[] = 'DROP TABLE KeepersTemp';
        // время обновления сведений
        $this->statements[] = 'DROP TABLE IF EXISTS Other';
        $this->statements[] = [
            'CREATE TABLE IF NOT EXISTS UpdateTime (',
            '    id INTEGER PRIMARY KEY NOT NULL,',
            '    ud INTEGER',
            ');'
        ];
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS updatetime_exists',
            'BEFORE INSERT ON UpdateTime',
            'WHEN EXISTS (SELECT id FROM UpdateTime WHERE id = NEW.id)',
            'BEGIN',
            '    UPDATE UpdateTime SET',
            '        ud = NEW.ud',
            '    WHERE id = NEW.id;',
            '    SELECT RAISE(IGNORE);',
            'END;'
        ];
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS updatetime_delete',
            'AFTER DELETE ON Forums FOR EACH ROW',
            'BEGIN',
            '    DELETE FROM UpdateTime WHERE id = OLD.id;',
            'END;',
        ];
        // данные от торрент-клиентов
        $this->statements[] = [
            'CREATE TABLE IF NOT EXISTS Clients (',
            '    hs VARCHAR NOT NULL,',
            '    cl INTEGER NOT NULL,',
            '    dl INTEGER,',
            '    PRIMARY KEY (hs,cl)',
            ');'
        ];
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS clients_exists',
            'BEFORE INSERT ON Clients',
            'WHEN EXISTS (SELECT hs FROM Clients WHERE hs = NEW.hs AND cl = NEW.cl)',
            'BEGIN',
            '    UPDATE Clients SET',
            '        dl = NEW.dl',
            '    WHERE hs = NEW.hs AND cl = NEW.cl;',
            '    SELECT RAISE(IGNORE);',
            'END;'
        ];
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS untracked_delete',
            'AFTER DELETE ON Clients FOR EACH ROW',
            'BEGIN',
            '    DELETE FROM TopicsUntracked WHERE hs = OLD.hs;',
            'END;'
        ];
        $this->statements[] = 'INSERT INTO Clients (hs,cl,dl) SELECT hs,cl,dl FROM Topics';
        // переносим хранимые неотслеживаемые раздачи
        $this->statements[] = [
            'CREATE TABLE IF NOT EXISTS TopicsUntracked (',
            '    id INT PRIMARY KEY NOT NULL,',
            '    ss INT,',
            '    na VARCHAR,',
            '    hs VARCHAR,',
            '    se INT,',
            '    si INT,',
            '    st INT,',
            '    rg INT',
            ');'
        ];
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS untracked_exists',
            'BEFORE INSERT ON TopicsUntracked',
            'WHEN EXISTS (SELECT id FROM TopicsUntracked WHERE id = NEW.id)',
            'BEGIN',
            '    UPDATE TopicsUntracked SET',
            '        ss = CASE WHEN NEW.ss IS NULL THEN ss ELSE NEW.ss END,',
            '        na = CASE WHEN NEW.na IS NULL THEN na ELSE NEW.na END,',
            '        hs = CASE WHEN NEW.hs IS NULL THEN hs ELSE NEW.hs END,',
            '        se = CASE WHEN NEW.se IS NULL THEN se ELSE NEW.se END,',
            '        si = CASE WHEN NEW.si IS NULL THEN si ELSE NEW.si END,',
            '        st = CASE WHEN NEW.st IS NULL THEN st ELSE NEW.st END,',
            '        rg = CASE WHEN NEW.rg IS NULL THEN rg ELSE NEW.rg END',
            '    WHERE id = NEW.id;',
            '    SELECT RAISE(IGNORE);',
            'END;'
        ];
        $this->statements[] = [
            'INSERT INTO TopicsUntracked (id,ss,na,hs,se,si,st,rg)',
            'SELECT id,ss,na,hs,se,si,st,rg FROM Topics',
            'WHERE dl = -2'
        ];
        $this->statements[] = 'DELETE FROM Topics WHERE dl = -2';
        // меняем структуру таблицы Blacklist
        $this->statements[] = 'ALTER TABLE Blacklist RENAME TO BlacklistTemp';
        $this->statements[] = [
            'CREATE TABLE IF NOT EXISTS Blacklist (',
            '    id INTEGER PRIMARY KEY NOT NULL,',
            '    comment VARCHAR',
            ');'
        ];
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS blacklist_exists',
            'BEFORE INSERT ON Blacklist',
            'WHEN EXISTS (SELECT id FROM Blacklist WHERE id = NEW.id)',
            'BEGIN',
            '    UPDATE Blacklist SET',
            '        comment = NEW.comment',
            '    WHERE id = NEW.id;',
            '    SELECT RAISE(IGNORE);',
            'END;'
        ];
        $this->statements[] = 'INSERT INTO Blacklist (id) SELECT topic_id FROM BlacklistTemp';
        $this->statements[] = 'DROP TABLE BlacklistTemp';
        // меняем структуру таблицы Topics
        $this->statements[] = 'DROP TRIGGER IF EXISTS delete_topics';
        $this->statements[] = 'ALTER TABLE Topics RENAME TO TopicsTemp';
        $this->statements[] = [
            'CREATE TABLE IF NOT EXISTS Topics (',
            '    id INT PRIMARY KEY NOT NULL,',
            '    ss INT,',
            '    na VARCHAR,',
            '    hs VARCHAR,',
            '    se INT,',
            '    si INT,',
            '    st INT,',
            '    rg INT,',
            '    qt INT,',
            '    ds INT',
            ');'
        ];
        $this->statements[] = [
            'INSERT INTO Topics (id,ss,na,hs,se,si,st,rg,qt,ds)',
            'SELECT id,ss,na,hs,se,si,st,rg,qt,ds FROM TopicsTemp'
        ];
        $this->statements[] = 'DROP TABLE TopicsTemp';
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS topic_exists',
            'BEFORE INSERT ON Topics',
            'WHEN EXISTS (SELECT id FROM Topics WHERE id = NEW.id)',
            'BEGIN',
            '    UPDATE Topics SET',
            '        ss = CASE WHEN NEW.ss IS NULL THEN ss ELSE NEW.ss END,',
            '        na = CASE WHEN NEW.na IS NULL THEN na ELSE NEW.na END,',
            '        hs = CASE WHEN NEW.hs IS NULL THEN hs ELSE NEW.hs END,',
            '        se = CASE WHEN NEW.se IS NULL THEN se ELSE NEW.se END,',
            '        si = CASE WHEN NEW.si IS NULL THEN si ELSE NEW.si END,',
            '        st = CASE WHEN NEW.st IS NULL THEN st ELSE NEW.st END,',
            '        rg = CASE WHEN NEW.rg IS NULL THEN rg ELSE NEW.rg END,',
            '        qt = CASE WHEN NEW.qt IS NULL THEN qt ELSE NEW.qt END,',
            '        ds = CASE WHEN NEW.ds IS NULL THEN ds ELSE NEW.ds END',
            '    WHERE id = NEW.id;',
            '    SELECT RAISE(IGNORE);',
            'END;'
        ];
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS seeders_insert',
            'AFTER INSERT ON Topics',
            'BEGIN',
            '    INSERT INTO Seeders (id) VALUES (NEW.id);',
            'END;'
        ];
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS seeders_transfer',
            'AFTER UPDATE ON Topics WHEN NEW.ds <> OLD.ds',
            'BEGIN',
            '    UPDATE Seeders SET',
            '        d0 = OLD.se,',
            '        d1 = d0,',
            '        d2 = d1,',
            '        d3 = d2,',
            '        d4 = d3,',
            '        d5 = d4,',
            '        d6 = d5,',
            '        d7 = d6,',
            '        d8 = d7,',
            '        d9 = d8,',
            '        d10 = d9,',
            '        d11 = d10,',
            '        d12 = d11,',
            '        d13 = d12,',
            '        d14 = d13,',
            '        d15 = d14,',
            '        d16 = d15,',
            '        d17 = d16,',
            '        d18 = d17,',
            '        d19 = d18,',
            '        d20 = d19,',
            '        d21 = d20,',
            '        d22 = d21,',
            '        d23 = d22,',
            '        d24 = d23,',
            '        d25 = d24,',
            '        d26 = d25,',
            '        d27 = d26,',
            '        d28 = d27,',
            '        d29 = d28,',
            '        q0 = OLD.qt,',
            '        q1 = q0,',
            '        q2 = q1,',
            '        q3 = q2,',
            '        q4 = q3,',
            '        q5 = q4,',
            '        q6 = q5,',
            '        q7 = q6,',
            '        q8 = q7,',
            '        q9 = q8,',
            '        q10 = q9,',
            '        q11 = q10,',
            '        q12 = q11,',
            '        q13 = q12,',
            '        q14 = q13,',
            '        q15 = q14,',
            '        q16 = q15,',
            '        q17 = q16,',
            '        q18 = q17,',
            '        q19 = q18,',
            '        q20 = q19,',
            '        q21 = q20,',
            '        q22 = q21,',
            '        q23 = q22,',
            '        q24 = q23,',
            '        q25 = q24,',
            '        q26 = q25,',
            '        q27 = q26,',
            '        q28 = q27,',
            '        q29 = q28',
            '    WHERE id = NEW.id;',
            'END;'
        ];
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS topics_delete',
            'AFTER DELETE ON Topics FOR EACH ROW',
            'BEGIN',
            '    DELETE FROM Seeders WHERE id = OLD.id;',
            '    DELETE FROM Blacklist WHERE id = OLD.id;',
            'END;'
        ];
        // триггер для обновления данных о подразделах
        $this->statements[] = 'DROP TRIGGER IF EXISTS Forums_update';
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS forums_exists',
            'BEFORE INSERT ON Forums',
            'WHEN EXISTS (SELECT id FROM Forums WHERE id = NEW.id)',
            'BEGIN',
            '    UPDATE Forums SET',
            '        na = NEW.na,',
            '        qt = NEW.qt,',
            '        si = NEW.si',
            '    WHERE id = NEW.id;',
            '    SELECT RAISE(IGNORE);',
            'END;'
        ];
        $this->statements[] = 'PRAGMA user_version = 4';
    }

    // user_version = 5
    private function setPragmaVersion_5(): void
    {
        $this->statements[] = 'ALTER TABLE Topics ADD COLUMN pt INT DEFAULT 1';
        $this->statements[] = 'DROP TRIGGER IF EXISTS topic_exists';
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS topic_exists',
            'BEFORE INSERT ON Topics',
            'WHEN EXISTS (SELECT id FROM Topics WHERE id = NEW.id)',
            'BEGIN',
            '    UPDATE Topics SET',
            '        ss = CASE WHEN NEW.ss IS NULL THEN ss ELSE NEW.ss END,',
            '        na = CASE WHEN NEW.na IS NULL THEN na ELSE NEW.na END,',
            '        hs = CASE WHEN NEW.hs IS NULL THEN hs ELSE NEW.hs END,',
            '        se = CASE WHEN NEW.se IS NULL THEN se ELSE NEW.se END,',
            '        si = CASE WHEN NEW.si IS NULL THEN si ELSE NEW.si END,',
            '        st = CASE WHEN NEW.st IS NULL THEN st ELSE NEW.st END,',
            '        rg = CASE WHEN NEW.rg IS NULL THEN rg ELSE NEW.rg END,',
            '        qt = CASE WHEN NEW.qt IS NULL THEN qt ELSE NEW.qt END,',
            '        ds = CASE WHEN NEW.ds IS NULL THEN ds ELSE NEW.ds END,',
            '        pt = CASE WHEN NEW.pt IS NULL THEN pt ELSE NEW.pt END',
            '    WHERE id = NEW.id;',
            '    SELECT RAISE(IGNORE);',
            'END;'
        ];
        $this->statements[] = 'PRAGMA user_version = 5';
    }

    // user_version = 6
    private function setPragmaVersion_6(): void
    {
        $this->statements[] = 'DROP TRIGGER IF EXISTS keepers_exists';
        $this->statements[] = 'ALTER TABLE Keepers ADD COLUMN complete INT DEFAULT 1';
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS keepers_exists',
            'BEFORE INSERT ON Keepers',
            'WHEN EXISTS (SELECT id FROM Keepers WHERE id = NEW.id AND nick = NEW.nick)',
            'BEGIN',
            '    UPDATE Keepers SET',
            '        posted = NEW.posted,',
            '        complete = NEW.complete',
            '    WHERE id = NEW.id AND nick = NEW.nick;',
            '    SELECT RAISE(IGNORE);',
            'END;'
        ];
        $this->statements[] = 'PRAGMA user_version = 6';
    }

    // user_version = 7
    private function setPragmaVersion_7(): void
    {
        $this->statements[] = [
            'CREATE TABLE IF NOT EXISTS KeepersSeeders (',
            '    topic_id INTEGER NOT NULL,',
            '    nick VARCHAR NOT NULL,',
            '    PRIMARY KEY (topic_id, nick)',
            ');'
        ];
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS keepers_seeders_exists',
            'BEFORE INSERT ON KeepersSeeders',
            'WHEN EXISTS (SELECT topic_id FROM KeepersSeeders WHERE topic_id = NEW.topic_id AND nick = NEW.nick)',
            'BEGIN',
            '    SELECT RAISE(IGNORE);',
            'END;'
        ];
        $this->statements[] = 'PRAGMA user_version = 7';
    }

    // user_version = 8
    private function setPragmaVersion_8(): void
    {
        $this->statements[] = [
            'CREATE TABLE IF NOT EXISTS Torrents (',
            '    info_hash     TEXT    NOT NULL,',
            '    client_id     INT     NOT NULL,',
            '    topic_id      INT,',
            '    name          TEXT,',
            '    total_size    INT,',
            '    paused        BOOLEAN DEFAULT (0),',
            '    done          REAL    DEFAULT (0),',
            '    time_added    INT     DEFAULT (strftime(\'%s\')),',
            '    error         BOOLEAN DEFAULT (0),',
            '    tracker_error TEXT,',
            '    PRIMARY KEY (',
            '        info_hash,',
            '        client_id',
            '    )',
            '    ON CONFLICT REPLACE',
            ');'
        ];

        $this->statements[] = [
            'INSERT INTO Torrents (info_hash, client_id, topic_id, name, total_size, paused, done, time_added, error)',
            'SELECT c.hs info_hash, c.cl client_id, t.id topic_id, t.na name, t.si total_size',
            '    ,CASE WHEN c.dl = -1 THEN 1 ELSE 0 END paused',
            '    ,CASE WHEN c.dl != 0 THEN 1 ELSE 0 END done',
            '    ,t.rg time_added, 0 error',
            'from Clients c',
            '    INNER JOIN Topics t ON t.hs = c.hs'
        ];

        $this->statements[] = ['DROP TABLE IF EXISTS Clients'];
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS remove_untracked_topics',
            'AFTER DELETE ON Torrents FOR EACH ROW',
            'BEGIN',
            '    DELETE FROM TopicsUntracked WHERE hs = OLD.info_hash;',
            'END;'
        ];
        $this->statements[] = 'PRAGMA user_version = 8';
    }

    // user_version = 9
    private function setPragmaVersion_9(): void
    {
        $this->statements[] = [
            'CREATE TABLE IF NOT EXISTS TopicsUnregistered (',
            '    info_hash           TEXT PRIMARY KEY ON CONFLICT REPLACE NOT NULL,',
            '    name                TEXT,',
            '    status              TEXT NOT NULL,',
            '    priority            TEXT,',
            '    transferred_from    TEXT,',
            '    transferred_to      TEXT,',
            '    transferred_by_whom TEXT',
            ');'
        ];
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS remove_unregistered_topics',
            'AFTER DELETE ON Torrents FOR EACH ROW',
            'BEGIN',
            '    DELETE FROM TopicsUnregistered WHERE info_hash = OLD.info_hash;',
            'END;'
        ];
        $this->statements[] = [
            'CREATE TABLE IF NOT EXISTS TopicsExcluded (',
            '    info_hash  TEXT PRIMARY KEY ON CONFLICT REPLACE NOT NULL,',
            '    time_added INT  DEFAULT (strftime(\'%s\')),',
            '    comment    TEXT',
            ');'
        ];
        $this->statements[] = [
            'INSERT INTO TopicsExcluded (info_hash, comment)',
            'SELECT Topics.hs, Blacklist.comment FROM Blacklist',
            'LEFT JOIN Topics ON Topics.id = Blacklist.id',
            'WHERE Blacklist.id IS NOT NULL'
        ];
        $this->statements[] = ['DROP TABLE IF EXISTS Blacklist'];
        $this->statements[] = ['DROP TRIGGER IF EXISTS topics_delete'];
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS topics_delete',
            'AFTER DELETE ON Topics FOR EACH ROW',
            'BEGIN',
            '    DELETE FROM Seeders WHERE id = OLD.id;',
            'END;'
        ];
        $this->statements[] = 'PRAGMA user_version = 9';
    }

    // user_version = 10
    private function setPragmaVersion_10(): void
    {
        // Новые поля в списке раздач.
        $this->statements[] = 'ALTER TABLE Topics ADD COLUMN ps INT DEFAULT 0'; // poster_id
        $this->statements[] = 'ALTER TABLE Topics ADD COLUMN ls INT DEFAULT 0'; // seeder_last_seen
        $this->statements[] = 'DROP TRIGGER IF EXISTS topic_exists';
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS topic_exists',
            'BEFORE INSERT ON Topics',
            'WHEN EXISTS (SELECT id FROM Topics WHERE id = NEW.id)',
            'BEGIN',
            '    UPDATE Topics SET',
            '        ss = CASE WHEN NEW.ss IS NULL THEN ss ELSE NEW.ss END,',
            '        na = CASE WHEN NEW.na IS NULL THEN na ELSE NEW.na END,',
            '        hs = CASE WHEN NEW.hs IS NULL THEN hs ELSE NEW.hs END,',
            '        se = CASE WHEN NEW.se IS NULL THEN se ELSE NEW.se END,',
            '        si = CASE WHEN NEW.si IS NULL THEN si ELSE NEW.si END,',
            '        st = CASE WHEN NEW.st IS NULL THEN st ELSE NEW.st END,',
            '        rg = CASE WHEN NEW.rg IS NULL THEN rg ELSE NEW.rg END,',
            '        qt = CASE WHEN NEW.qt IS NULL THEN qt ELSE NEW.qt END,',
            '        ds = CASE WHEN NEW.ds IS NULL THEN ds ELSE NEW.ds END,',
            '        pt = CASE WHEN NEW.pt IS NULL THEN pt ELSE NEW.pt END,',
            '        ps = CASE WHEN NEW.ps IS NULL THEN ps ELSE NEW.ps END,',
            '        ls = CASE WHEN NEW.ls IS NULL THEN ls ELSE NEW.ls END',
            '    WHERE id = NEW.id;',
            '    SELECT RAISE(IGNORE);',
            'END;'
        ];
        $this->statements[] = 'CREATE INDEX IF NOT EXISTS IX_Topics_ss_hs ON Topics (ss, hs);';

        $this->statements[] = 'PRAGMA user_version = 10';
    }

    // user_version = 11
    private function setPragmaVersion_11(): void
    {
        // Пересоздадим таблицу раздач других хранителей.
        $this->statements[] = ['DROP TABLE IF EXISTS Keepers'];
        $this->statements[] = ['DROP TRIGGER IF EXISTS keepers_exists'];
        $this->statements[] = [
            'CREATE TABLE IF NOT EXISTS KeepersLists (',
            '    topic_id INTEGER NOT NULL,',
            '    keeper_id INTEGER NOT NULL,',
            '    keeper_name VARCHAR,',
            '    posted INTEGER,',
            '    complete INT DEFAULT 1,',
            '    PRIMARY KEY (topic_id, keeper_id)',
            ');'
        ];
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS keepers_lists_exists',
            'BEFORE INSERT ON KeepersLists',
            'WHEN EXISTS (SELECT topic_id FROM KeepersLists WHERE topic_id = NEW.topic_id AND keeper_id = NEW.keeper_id)',
            'BEGIN',
            '    UPDATE KeepersLists SET',
            '        keeper_name = NEW.keeper_name,',
            '        posted      = NEW.posted,',
            '        complete    = NEW.complete',
            '    WHERE topic_id = NEW.topic_id AND keeper_id = NEW.keeper_id;',
            '    SELECT RAISE(IGNORE);',
            'END;'
        ];

        // Список сидов-хранителей.
        $this->statements[] = ['DROP TABLE IF EXISTS KeepersSeeders'];
        $this->statements[] = ['DROP TRIGGER IF EXISTS keepers_seeders_exists'];
        $this->statements[] = [
            'CREATE TABLE IF NOT EXISTS KeepersSeeders (',
            '    topic_id INTEGER NOT NULL,',
            '    keeper_id INTEGER NOT NULL,',
            '    keeper_name VARCHAR,',
            '    PRIMARY KEY (topic_id, keeper_id)',
            ');'
        ];
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS keepers_seeders_exists',
            'BEFORE INSERT ON KeepersSeeders',
            'WHEN EXISTS (SELECT topic_id FROM KeepersSeeders WHERE topic_id = NEW.topic_id AND keeper_id = NEW.keeper_id)',
            'BEGIN',
            '    UPDATE KeepersSeeders SET',
            '        keeper_name = NEW.keeper_name',
            '    WHERE topic_id = NEW.topic_id AND keeper_id = NEW.keeper_id;',
            '    SELECT RAISE(IGNORE);',
            'END;'
        ];

        $this->statements[] = 'PRAGMA user_version = 11';
    }

    // user_version = 12
    private function setPragmaVersion_12(): void
    {
        // Изменим таблицу списка подразделов.
        $this->statements[] = 'ALTER TABLE Forums RENAME COLUMN na TO name;';
        $this->statements[] = 'ALTER TABLE Forums RENAME COLUMN qt TO quantity;';
        $this->statements[] = 'ALTER TABLE Forums RENAME COLUMN si TO "size";';

        $this->statements[] = 'DROP TRIGGER IF EXISTS forums_exists';
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS forums_exists',
            'BEFORE INSERT ON Forums',
            'WHEN EXISTS (SELECT id FROM Forums WHERE id = NEW.id)',
            'BEGIN',
            '    UPDATE Forums SET',
            '        name     = NEW.name,',
            '        quantity = NEW.quantity,',
            '        size     = NEW.size',
            '    WHERE id = NEW.id;',
            '    SELECT RAISE(IGNORE);',
            'END;'
        ];

        // Создадим таблицу дополнительных сведений о хранимых подразделах.
        $this->statements[] = 'DROP TABLE   IF EXISTS ForumsOptions';
        $this->statements[] = 'DROP TRIGGER IF EXISTS forums_options_exists';
        $this->statements[] = [
            'CREATE TABLE IF NOT EXISTS ForumsOptions (',
            '    forum_id       INT PRIMARY KEY,',
            '    topic_id       INT,',
            '    author_id      INT,',
            '    author_name    VARCHAR,',
            '    author_post_id INT,',
            '    post_ids       JSON',
            ');'
        ];
        $this->statements[] = [
            'CREATE TRIGGER IF NOT EXISTS forums_options_exists',
            'BEFORE INSERT ON ForumsOptions',
            'WHEN EXISTS (SELECT forum_id FROM ForumsOptions WHERE forum_id = NEW.forum_id)',
            'BEGIN',
            '    UPDATE ForumsOptions SET',
            '        topic_id       = CASE WHEN NEW.topic_id       IS NULL THEN topic_id       ELSE NEW.topic_id END,',
            '        author_id      = CASE WHEN NEW.author_id      IS NULL THEN author_id      ELSE NEW.author_id END,',
            '        author_name    = CASE WHEN NEW.author_name    IS NULL THEN author_name    ELSE NEW.author_name END,',
            '        author_post_id = CASE WHEN NEW.author_post_id IS NULL THEN author_post_id ELSE NEW.author_post_id END,',
            '        post_ids       = CASE WHEN NEW.post_ids       IS NULL THEN post_ids       ELSE NEW.post_ids END',
            '    WHERE forum_id = NEW.forum_id;',
            '    SELECT RAISE(IGNORE);',
            'END;'
        ];

        // Удалим признак обновления дерева подразделов.
        $this->statements[] = 'DELETE FROM UpdateTime WHERE id = 8888';
        // Удалим признакми обновления списков других хранителей, для обновления параметров подразделов.
        $this->statements[] = 'DELETE FROM UpdateTime WHERE id BETWEEN 100000 AND 200000';

        $this->statements[] = 'CREATE INDEX IF NOT EXISTS IX_Torrents_error ON Torrents (error);';

        $this->statements[] = 'PRAGMA user_version = 12';
    }
}
