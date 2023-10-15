<?php

use KeepersTeam\Webtlo\Helper;

include_once dirname(__FILE__) . "/../migration/DatabaseMigration.php";

class Db
{
    public static $db;
    private static string $databaseFileName = 'webtlo.db';

    /** Актуальная версия БД */
    private const PRAGMA_VERSION = 12;

    public static function query_database($sql, $param = [], $fetch = false, $pdo = PDO::FETCH_ASSOC)
    {
        $sth = self::prepare_query($sql, $param);
        return $fetch ? $sth->fetchAll($pdo) : true;
    }

    public static function query_database_row($sql, $param = [], $fetch = false, $pdo = PDO::FETCH_ASSOC)
    {
        $sth = self::prepare_query($sql, $param);
        return $fetch ? $sth->fetch($pdo) : true;
    }

    private static function prepare_query($sql, $param = [])
    {
        try {
            self::$db->sqliteCreateFunction('like', 'Db::lexa_ci_utf8_like', 2);
            $sth = self::$db->prepare($sql);
            if (self::$db->errorCode() != '0000') {
                $error = self::$db->errorInfo();
                throw new Exception('SQL ошибка: ' . $error[2]);
            }
        } catch (Exception $e) {
            Log::append($sql);
            throw new Exception($e->getMessage(), (int)$e->getCode(), $e);
        }
        $sth->execute($param);
        return $sth;
    }

    /**
     * Выполнить запрос подсчёта количества записей в таблице.
     */
    public static function query_count($sql, $param = []): int
    {
        return self::query_database_row($sql, $param, true, PDO::FETCH_COLUMN) ?? 0;
    }

    /**
     * Посчитать количество записей в таблице.
     */
    public static function select_count(string $table): int
    {
        return self::query_count("SELECT COUNT() FROM $table") ?? 0;
    }

    /**
     * Создать временную таблицу как копию существующей.
     */
    public static function temp_copy_table(string $table, array $keys = [], string $prefix = 'New'): string
    {
        $copyTable = $prefix . $table;
        $tempTable = "temp.$copyTable";
        $tempKeys  = count($keys) ? implode(',', $keys) : '*';

        $sql = "CREATE TEMP TABLE $copyTable AS SELECT $tempKeys FROM $table WHERE 0 = 1";
        self::query_database($sql);

        return $tempTable;
    }

    /**
     * Создать временную таблицу по списку полей.
     */
    public static function temp_keys_table(string $table, array $keys): string
    {
        $tempTable = "temp.$table";

        $sql = sprintf('CREATE TEMP TABLE %s (%s)', $table, implode(',', $keys));
        self::query_database($sql);

        return $tempTable;
    }


    /**
     * Вставить в таблицу массив сырых данных.
     */
    public static function table_insert_dataset(
        string $table,
        array $dataset,
        string $primaryKey = 'id',
        array $keys = []
    ): void {
        $keys = count($keys) ? sprintf('(%s)', implode(',', $keys)) : '';
        $sql  = "INSERT INTO $table $keys " . self::combine_set($dataset, $primaryKey);

        self::query_database($sql);
    }

    /**
     * Перенести данные из временной таблицы в основную.
     */
    public static function table_insert_temp(string $table, string $tempTable, array $keys = []): void
    {
        $insKeys = '';
        $selKeys = '*';

        if (count($keys)) {
            $selKeys = implode(',', $keys);
            $insKeys = sprintf('(%s)', $selKeys);
        }

        $sql  = "INSERT INTO $table $insKeys SELECT $selKeys FROM $tempTable";
        self::query_database($sql);
    }

    // https://blog.amartynov.ru/php-sqlite-case-insensitive-like-utf8/
    public static function lexa_ci_utf8_like($mask, $value)
    {
        $mask = str_replace(
            ["%", "_"],
            [".*?", "."],
            preg_quote($mask, "/")
        );
        $mask = "/^$mask$/ui";
        return preg_match($mask, $value);
    }

    public static function combine_set($set, $primaryKey = 'id')
    {
        foreach ($set as $id => &$value) {
            $value = array_map(function ($e) {
                return is_numeric($e) ? $e : Db::$db->quote($e);
            }, $value);
            $value = (empty($value[$primaryKey]) ? "$id," : "") . implode(',', $value);
        }
        $statement = 'SELECT ' . implode(' UNION ALL SELECT ', $set);
        return $statement;
    }

    /**
     * объединение нескольких запросов на получение данных
     * @param array $source
     * @return string|bool
     */
    public static function unionQuery($source)
    {
        if (!is_array($source)) {
            return false;
        }
        $query = '';
        $values = [];
        foreach ($source as &$row) {
            if (!is_array($row)) {
                return false;
            }
            $row = array_map(
                function ($e) {
                    return is_numeric($e) ? $e : Db::$db->quote($e);
                },
                $row
            );
            $values[] = implode(',', $row);
        }
        $query = 'SELECT ' . implode(' UNION ALL SELECT ', $values);
        return $query;
    }

    private static function getCurrentPragma(): int
    {
        $version = DB::query_database_row('PRAGMA user_version', [], true, PDO::FETCH_COLUMN);
        return $version ?? 0;
    }

    public static function create()
    {
        // файл базы данных
        $databaseDirName = Helper::getStorageDir();
        $databasePath = $databaseDirName . DIRECTORY_SEPARATOR . Db::$databaseFileName;
        Helper::checkDirRecursive($databaseDirName);

        try {
            self::$db = new PDO('sqlite:' . $databasePath);
        } catch (PDOException $e) {
            throw new Exception(sprintf('Не удалось подключиться к БД в "%s", причина: %s', $databasePath, $e));
        }

        $currentPragma = self::getCurrentPragma();
        $statements = [];
        if ($currentPragma > self::PRAGMA_VERSION) {
            throw new Exception(sprintf('Ваша версия БД (#%d), опережает указанную в настройках web-TLO. Вероятно, вы откатились на прошлую версию программы. Удалите файл БД и запустите обновление сведений.', $currentPragma));
        } elseif ($currentPragma === 0) {
            // Создание БД с нуля
            $statements = self::initTables();
        } elseif ($currentPragma > 0) {
            // Создаём запросы для миграции БД.
            $statements = (new DatabaseMigration())->getStatements($currentPragma, self::PRAGMA_VERSION);

            // Бекапим БД при изменении версии.
            if (count($statements)) {
                Backup::database($databasePath, $currentPragma);
            }
        }

        // формируем структуру БД
        foreach ($statements as &$statement) {
            if (is_array($statement)) {
                $statement = implode(PHP_EOL, $statement);
            }
            self::query_database($statement);
            unset($statement);
        }
    }

    private static function initTables(): array
    {
        $statements = [];
        // Создадим таблицу списка подразделов.
        $statements[] = [
            'CREATE TABLE IF NOT EXISTS Forums (',
            '    id       INT NOT NULL PRIMARY KEY,',
            '    name     VARCHAR NOT NULL,',
            '    quantity INT,',
            '    size     INT',
            ');'
        ];
        $statements[] = [
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
        $statements[] = [
            'CREATE TABLE IF NOT EXISTS ForumsOptions (',
            '    forum_id       INT PRIMARY KEY,',
            '    topic_id       INT,',
            '    author_id      INT,',
            '    author_name    VARCHAR,',
            '    author_post_id INT,',
            '    post_ids       JSON',
            ');'
        ];
        $statements[] = [
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

        // Список хранителей по спискам.
        $statements[] = [
            'CREATE TABLE IF NOT EXISTS KeepersLists (',
            '    topic_id INTEGER NOT NULL,',
            '    keeper_id INTEGER NOT NULL,',
            '    keeper_name VARCHAR,',
            '    posted INTEGER,',
            '    complete INT DEFAULT 1,',
            '    PRIMARY KEY (topic_id, keeper_id)',
            ');'
        ];
        $statements[] = [
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
        $statements[] = [
            'CREATE TABLE IF NOT EXISTS KeepersSeeders (',
            '    topic_id INTEGER NOT NULL,',
            '    keeper_id INTEGER NOT NULL,',
            '    keeper_name VARCHAR,',
            '    PRIMARY KEY (topic_id, keeper_id)',
            ');'
        ];
        $statements[] = [
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

        // Данные раздач по данным форума.
        $statements[] = [
            'CREATE TABLE IF NOT EXISTS Topics (',
            '    id INT PRIMARY KEY NOT NULL,', // topic_id
            '    ss INT,',                      // forum_id
            '    na VARCHAR,',                  // topic_title
            '    hs VARCHAR,',                  // info_hash
            '    se INT,',                      // sum_seeders
            '    si INT,',                      // tor_size_bytes
            '    st INT,',                      // tor_status
            '    rg INT,',                      // reg_time
            '    qt INT,',                      // sum_updates
            '    ds INT,',                      // days_update
            '    pt INT DEFAULT 1,',            // keeping_priority
            '    ps INT DEFAULT 0,',            // poster_id
            '    ls INT DEFAULT 0',             // seeder_last_seen
            ');'
        ];
        $statements[] = [
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
        $statements[] = 'CREATE INDEX IF NOT EXISTS IX_Topics_ss_hs ON Topics (ss, hs);';
        $statements[] = [
            'CREATE TRIGGER IF NOT EXISTS topics_delete',
            'AFTER DELETE ON Topics FOR EACH ROW',
            'BEGIN',
            '    DELETE FROM Seeders WHERE id = OLD.id;',
            'END;'
        ];

        // Исторические данные по средним сидам.
        $statements[] = [
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
        $statements[] = [
            'CREATE TRIGGER IF NOT EXISTS seeders_insert',
            'AFTER INSERT ON Topics',
            'BEGIN',
            '    INSERT INTO Seeders (id) VALUES (NEW.id);',
            'END;'
        ];
        $statements[] = [
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

        // Исключённые раздачи, чёрный список.
        $statements[] = [
            'CREATE TABLE IF NOT EXISTS TopicsExcluded (',
            '    info_hash  TEXT PRIMARY KEY ON CONFLICT REPLACE NOT NULL,',
            '    time_added INT  DEFAULT (strftime(\'%s\')),',
            '    comment    TEXT',
            ')'
        ];

        // Разрегистрированные раздачи, по данным форума.
        $statements[] = [
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

        // Неотслеживаемые раздачи, из нехранимых подразделов.
        $statements[] = [
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
        $statements[] = [
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

        // Хранимые раздачи, по данным торрент-клиентов.
        $statements[] = [
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
            ')'
        ];
        $statements[] = 'CREATE INDEX IF NOT EXISTS IX_Torrents_error ON Torrents (error);';
        $statements[] = [
            'CREATE TRIGGER IF NOT EXISTS remove_unregistered_topics',
            'AFTER DELETE ON Torrents FOR EACH ROW',
            'BEGIN',
            '    DELETE FROM TopicsUnregistered WHERE info_hash = OLD.info_hash;',
            'END;'
        ];
        $statements[] = [
            'CREATE TRIGGER IF NOT EXISTS remove_untracked_topics',
            'AFTER DELETE ON Torrents FOR EACH ROW',
            'BEGIN',
            '    DELETE FROM TopicsUntracked WHERE hs = OLD.info_hash;',
            'END;'
        ];

        // Время обновления сведений.
        $statements[] = [
            'CREATE TABLE IF NOT EXISTS UpdateTime (',
            '    id INTEGER PRIMARY KEY NOT NULL,',
            '    ud INTEGER',
            ')'
        ];
        $statements[] = [
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
        $statements[] = [
            'CREATE TRIGGER IF NOT EXISTS updatetime_delete',
            'AFTER DELETE ON Forums FOR EACH ROW',
            'BEGIN',
            '    DELETE FROM UpdateTime WHERE id = OLD.id;',
            'END;',
        ];

        // Запишем текущую версию БД.
        $statements[] = sprintf('PRAGMA user_version = %d', self::PRAGMA_VERSION);

        return $statements;
    }
}
