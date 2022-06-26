<?php
include_once dirname(__FILE__) . "/../common/storage.php";

class Db
{

    public static $db;
    private static $databaseFilename = 'webtlo.db';

    public static function query_database($sql, $param = array(), $fetch = false, $pdo = PDO::FETCH_ASSOC)
    {
        self::$db->sqliteCreateFunction('like', 'Db::lexa_ci_utf8_like', 2);
        $sth = self::$db->prepare($sql);
        if (self::$db->errorCode() != '0000') {
            $error = self::$db->errorInfo();
            throw new Exception('SQL ошибка: ' . $error[2]);
        }
        $sth->execute($param);
        return $fetch ? $sth->fetchAll($pdo) : true;
    }

    // https://blog.amartynov.ru/php-sqlite-case-insensitive-like-utf8/
    public static function lexa_ci_utf8_like($mask, $value)
    {
        $mask = str_replace(
            array("%", "_"),
            array(".*?", "."),
            preg_quote($mask, "/")
        );
        $mask = "/^$mask$/ui";
        return preg_match($mask, $value);
    }

    public static function combine_set($set)
    {
        foreach ($set as $id => &$value) {
            $value = array_map(function ($e) {
                return is_numeric($e) ? $e : Db::$db->quote($e);
            }, $value);
            $value = (empty($value['id']) ? "$id," : "") . implode(',', $value);
        }
        $statement = 'SELECT ' . implode(' UNION ALL SELECT ', $set);
        return $statement;
    }

    public static function create()
    {
        // файл базы данных

        $dataDirname = getStorageDir();
        $databasePath = $dataDirname . DIRECTORY_SEPARATOR . Db::$databaseFilename;

        if (!file_exists($dataDirname)) {
            if (!mkdir_recursive($dataDirname)) {
                throw new Exception('Не удалось создать каталог ' . $dataDirname);
            }
        }
        try {
            self::$db = new PDO('sqlite:' . $databasePath);
        } catch (PDOException $e) {
            throw new Exception(sprintf('Не удалось подключиться к БД в "%s", причина: %s', $databasePath, $e));
        }

        // список подразделов
        $statements[] = array(
            'CREATE TABLE IF NOT EXISTS Forums (',
            '    id INT NOT NULL PRIMARY KEY,',
            '    na VARCHAR NOT NULL',
            ')'
        );
        // список раздач
        $statements[] = array(
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
            ')'
        );
        // средние сиды
        $statements[] = array(
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
            ')'
        );
        // список хранимого другими
        $statements[] = array(
            'CREATE TABLE IF NOT EXISTS Keepers (',
            '    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,',
            '    topic_id INTEGER NOT NULL,',
            '    nick VARCHAR NOT NULL',
            ')'
        );
        // совместимость со старыми версиями базы данных
        $version = self::query_database('PRAGMA user_version', array(), true);
        // user_version = 1
        if ($version[0]['user_version'] < 1) {
            $statements[] = 'ALTER TABLE Topics ADD COLUMN rt INT DEFAULT 1';
            $statements[] = 'ALTER TABLE Topics ADD COLUMN ds INT DEFAULT 0';
            $statements[] = 'ALTER TABLE Topics ADD COLUMN cl VARCHAR';
            $statements[] = 'PRAGMA user_version = 1';
        }
        // user_version = 2
        if ($version[0]['user_version'] < 2) {
            $statements[] = 'DROP TRIGGER IF EXISTS Seeders_update';
            $statements[] = 'ALTER TABLE Forums ADD COLUMN qt INT';
            $statements[] = 'ALTER TABLE Forums ADD COLUMN si INT';
            $statements[] = 'ALTER TABLE Topics RENAME TO TopicsTemp';
            $statements[] = array(
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
                ')'
            );
            $statements[] = array(
                'INSERT INTO Topics (id,ss,na,hs,se,si,st,rg,dl,qt,ds,cl)',
                'SELECT id,ss,na,hs,se,si,st,rg,dl,rt,ds,cl FROM TopicsTemp'
            );
            $statements[] = 'DROP TABLE TopicsTemp';
            $statements[] = array(
                'CREATE TRIGGER IF NOT EXISTS delete_seeders',
                'AFTER DELETE ON Topics FOR EACH ROW',
                'BEGIN',
                '    DELETE FROM Seeders WHERE id = OLD.id;',
                'END;'
            );
            $statements[] = array(
                'CREATE TRIGGER IF NOT EXISTS insert_seeders',
                'AFTER INSERT ON Topics',
                'BEGIN',
                '    INSERT INTO Seeders (id) VALUES (NEW.id);',
                'END;'
            );
            $statements[] = array(
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
            );
            $statements[] = array(
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
            );
            $statements[] = 'PRAGMA user_version = 2';
        }
        // // user_version = 3
        if ($version[0]['user_version'] < 3) {
            $statements[] = array(
                'CREATE TABLE IF NOT EXISTS Blacklist (',
                '    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,',
                '    topic_id INTEGER NOT NULL',
                ')'
            );
            $statements[] = 'DROP TRIGGER delete_seeders';
            $statements[] = array(
                'CREATE TRIGGER IF NOT EXISTS delete_topics',
                'AFTER DELETE ON Topics FOR EACH ROW',
                'BEGIN',
                '    DELETE FROM Seeders WHERE id = OLD.id;',
                '    DELETE FROM Blacklist WHERE topic_id = OLD.id;',
                'END;'
            );
            $statements[] = 'PRAGMA user_version = 3';
        }
        // user_version = 4
        if ($version[0]['user_version'] < 4) {
            // меняем структуру таблицы Keepers
            $statements[] = 'ALTER TABLE Keepers RENAME TO KeepersTemp';
            $statements[] = array(
                'CREATE TABLE IF NOT EXISTS Keepers (',
                '    id INTEGER NOT NULL,',
                '    nick VARCHAR NOT NULL,',
                '    posted INTEGER,',
                '    PRIMARY KEY (id, nick)',
                ')'
            );
            $statements[] = array(
                'CREATE TRIGGER IF NOT EXISTS keepers_exists',
                'BEFORE INSERT ON Keepers',
                'WHEN EXISTS (SELECT id FROM Keepers WHERE id = NEW.id AND nick = NEW.nick)',
                'BEGIN',
                '    UPDATE Keepers SET',
                '        posted = NEW.posted',
                '    WHERE id = NEW.id AND nick = NEW.nick;',
                '    SELECT RAISE(IGNORE);',
                'END;'
            );
            $statements[] = 'INSERT INTO Keepers (id,nick) SELECT topic_id,nick FROM KeepersTemp';
            $statements[] = 'DROP TABLE KeepersTemp';
            // время обновления сведений
            $statements[] = 'DROP TABLE IF EXISTS Other';
            $statements[] = array(
                'CREATE TABLE IF NOT EXISTS UpdateTime (',
                '    id INTEGER PRIMARY KEY NOT NULL,',
                '    ud INTEGER',
                ')'
            );
            $statements[] = array(
                'CREATE TRIGGER IF NOT EXISTS updatetime_exists',
                'BEFORE INSERT ON UpdateTime',
                'WHEN EXISTS (SELECT id FROM UpdateTime WHERE id = NEW.id)',
                'BEGIN',
                '    UPDATE UpdateTime SET',
                '        ud = NEW.ud',
                '    WHERE id = NEW.id;',
                '    SELECT RAISE(IGNORE);',
                'END;'
            );
            $statements[] = array(
                'CREATE TRIGGER IF NOT EXISTS updatetime_delete',
                'AFTER DELETE ON Forums FOR EACH ROW',
                'BEGIN',
                '    DELETE FROM UpdateTime WHERE id = OLD.id;',
                'END;',
            );
            // данные от торрент-клиентов
            $statements[] = array(
                'CREATE TABLE IF NOT EXISTS Clients (',
                '    hs VARCHAR NOT NULL,',
                '    cl INTEGER NOT NULL,',
                '    dl INTEGER,',
                '    PRIMARY KEY (hs,cl)',
                ')'
            );
            $statements[] = array(
                'CREATE TRIGGER IF NOT EXISTS clients_exists',
                'BEFORE INSERT ON Clients',
                'WHEN EXISTS (SELECT hs FROM Clients WHERE hs = NEW.hs AND cl = NEW.cl)',
                'BEGIN',
                '    UPDATE Clients SET',
                '        dl = NEW.dl',
                '    WHERE hs = NEW.hs AND cl = NEW.cl;',
                '    SELECT RAISE(IGNORE);',
                'END;'
            );
            $statements[] = array(
                'CREATE TRIGGER IF NOT EXISTS untracked_delete',
                'AFTER DELETE ON Clients FOR EACH ROW',
                'BEGIN',
                '    DELETE FROM TopicsUntracked WHERE hs = OLD.hs;',
                'END;'
            );
            $statements[] = 'INSERT INTO Clients (hs,cl,dl) SELECT hs,cl,dl FROM Topics';
            // переносим хранимые неотслеживаемые раздачи
            $statements[] = array(
                'CREATE TABLE IF NOT EXISTS TopicsUntracked (',
                '    id INT PRIMARY KEY NOT NULL,',
                '    ss INT,',
                '    na VARCHAR,',
                '    hs VARCHAR,',
                '    se INT,',
                '    si INT,',
                '    st INT,',
                '    rg INT',
                ')'
            );
            $statements[] = array(
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
            );
            $statements[] = array(
                'INSERT INTO TopicsUntracked (id,ss,na,hs,se,si,st,rg)',
                'SELECT id,ss,na,hs,se,si,st,rg FROM Topics',
                'WHERE dl = -2'
            );
            $statements[] = 'DELETE FROM Topics WHERE dl = -2';
            // меняем структуру таблицы Blacklist
            $statements[] = 'ALTER TABLE Blacklist RENAME TO BlacklistTemp';
            $statements[] = array(
                'CREATE TABLE IF NOT EXISTS Blacklist (',
                '    id INTEGER PRIMARY KEY NOT NULL,',
                '    comment VARCHAR',
                ')'
            );
            $statements[] = array(
                'CREATE TRIGGER IF NOT EXISTS blacklist_exists',
                'BEFORE INSERT ON Blacklist',
                'WHEN EXISTS (SELECT id FROM Blacklist WHERE id = NEW.id)',
                'BEGIN',
                '    UPDATE Blacklist SET',
                '        comment = NEW.comment',
                '    WHERE id = NEW.id;',
                '    SELECT RAISE(IGNORE);',
                'END;'
            );
            $statements[] = 'INSERT INTO Blacklist (id) SELECT topic_id FROM BlacklistTemp';
            $statements[] = 'DROP TABLE BlacklistTemp';
            // меняем структуру таблицы Topics
            $statements[] = 'DROP TRIGGER IF EXISTS delete_topics';
            $statements[] = 'ALTER TABLE Topics RENAME TO TopicsTemp';
            $statements[] = array(
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
                ')'
            );
            $statements[] = array(
                'INSERT INTO Topics (id,ss,na,hs,se,si,st,rg,qt,ds)',
                'SELECT id,ss,na,hs,se,si,st,rg,qt,ds FROM TopicsTemp'
            );
            $statements[] = 'DROP TABLE TopicsTemp';
            $statements[] = array(
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
            );
            $statements[] = array(
                'CREATE TRIGGER IF NOT EXISTS seeders_insert',
                'AFTER INSERT ON Topics',
                'BEGIN',
                '    INSERT INTO Seeders (id) VALUES (NEW.id);',
                'END;'
            );
            $statements[] = array(
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
            );
            $statements[] = array(
                'CREATE TRIGGER IF NOT EXISTS topics_delete',
                'AFTER DELETE ON Topics FOR EACH ROW',
                'BEGIN',
                '    DELETE FROM Seeders WHERE id = OLD.id;',
                '    DELETE FROM Blacklist WHERE id = OLD.id;',
                'END;'
            );
            // триггер для обновления данных о подразделах
            $statements[] = 'DROP TRIGGER IF EXISTS Forums_update';
            $statements[] = array(
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
            );
            $statements[] = 'PRAGMA user_version = 4';
        }
        // user_version = 5
        if ($version[0]['user_version'] < 5) {
            $statements[] = 'ALTER TABLE Topics ADD COLUMN pt INT DEFAULT 1';
            $statements[] = 'DROP TRIGGER IF EXISTS topic_exists';
            $statements[] = array(
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
            );
            $statements[] = 'PRAGMA user_version = 5';
        }
        // user_version = 6
        if ($version[0]['user_version'] < 6) {
            $statements[] = 'DROP TRIGGER IF EXISTS keepers_exists';
            $statements[] = 'ALTER TABLE Keepers ADD COLUMN complete INT DEFAULT 1';
            $statements[] = array(
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
            );
            $statements[] = 'PRAGMA user_version = 6';
        }
        // user_version = 7
        if ($version[0]['user_version'] < 7) {
            $statements[] = array(
                'CREATE TABLE IF NOT EXISTS KeepersSeeders (',
                '    topic_id INTEGER NOT NULL,',
                '    nick VARCHAR NOT NULL,',
                '    PRIMARY KEY (topic_id, nick)',
                ')'
            );
            $statements[] = array(
                'CREATE TRIGGER IF NOT EXISTS keepers_seeders_exists',
                'BEFORE INSERT ON KeepersSeeders',
                'WHEN EXISTS (SELECT topic_id FROM KeepersSeeders WHERE topic_id = NEW.topic_id AND nick = NEW.nick)',
                'BEGIN',
                '    SELECT RAISE(IGNORE);',
                'END;'
            );
            $statements[] = 'PRAGMA user_version = 7';
        }
        // формируем структуру БД
        foreach ($statements as &$statement) {
            if (is_array($statement)) {
                $statement = implode(PHP_EOL, $statement);
            }
            self::query_database($statement);
        }
    }
}
