<?php

namespace KeepersTeam\Webtlo\Legacy;

use KeepersTeam\Webtlo\DB as ModernDB;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Module\LastUpdate;
use KeepersTeam\Webtlo\TIniFileEx;
use PDO;
use Exception;

final class Db
{
    public static ?ModernDB $db = null;

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
        self::checkConnect();

        return self::$db->executeStatement($sql, $param);
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
        return self::query_count("SELECT COUNT() FROM $table");
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
        array  $dataset,
        string $primaryKey = 'id',
        array  $keys = []
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

        $sql = "INSERT INTO $table $insKeys SELECT $selKeys FROM $tempTable";
        self::query_database($sql);
    }

    public static function combine_set($set, $primaryKey = 'id'): string
    {
        self::checkConnect();

        foreach ($set as $id => &$value) {
            $value = array_map(function($e) {
                return is_numeric($e) ? $e : self::$db->db->quote($e ?? '');
            }, $value);
            $value = (empty($value[$primaryKey]) ? "$id," : "") . implode(',', $value);
        }

        return 'SELECT ' . implode(' UNION ALL SELECT ', $set);
    }

    /**
     * объединение нескольких запросов на получение данных
     *
     * @param array $source
     * @return string|bool
     */
    public static function unionQuery($source)
    {
        self::checkConnect();

        if (!is_array($source)) {
            return false;
        }
        $query  = '';
        $values = [];
        foreach ($source as &$row) {
            if (!is_array($row)) {
                return false;
            }
            $row      = array_map(
                function($e) {
                    return is_numeric($e) ? $e : self::$db->db->quote($e ?? '');
                },
                $row
            );
            $values[] = implode(',', $row);
        }
        $query = 'SELECT ' . implode(' UNION ALL SELECT ', $values);

        return $query;
    }

    /**
     * @throws Exception
     */
    public static function create(): void
    {
        self::$db = ModernDB::create();

        self::clearTables();
    }

    private static function checkConnect(): void
    {
        if (null === self::$db) {
            self::create();
        }
    }

    /** Удалим устаревшие данные о раздачах. */
    private static function clearTables(): void
    {
        // Данные о сидах устарели
        $avgSeedersPeriodOutdated = TIniFileEx::read('sections', 'avg_seeders_period_outdated', 7);

        $outdatedTime = time() - (int)$avgSeedersPeriodOutdated * 86400;

        // Удалим устаревшие метки обновлений.
        self::query_database(
            'DELETE FROM UpdateTime WHERE ud < ?',
            [$outdatedTime]
        );

        // Удалим раздачи из подразделов, для которых нет актуальных меток обновления.
        self::query_database(
            '
            DELETE FROM Topics
            WHERE keeping_priority <> 2
                AND forum_id NOT IN (SELECT id FROM UpdateTime WHERE id < 100000)
        '
        );

        // Если используется алгоритм получения раздач высокого приоритета - их тоже нужно чистить.
        $updatePriority = (bool)TIniFileEx::read('update', 'priority', 0);
        if ($updatePriority) {
            // Удалим устаревшие раздачи высокого приоритета.
            $lastHighUpdate = LastUpdate::getTime(UpdateMark::HIGH_PRIORITY->value);
            if ($lastHighUpdate < $outdatedTime) {
                self::query_database(
                    '
                    DELETE FROM Topics
                    WHERE keeping_priority = 2
                        AND forum_id NOT IN (SELECT id FROM UpdateTime WHERE id < 100000)
                '
                );
            }
        }
    }
}
