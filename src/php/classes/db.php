<?php

use KeepersTeam\Webtlo\DB as ModernDB;

class Db
{
    public static ModernDB $db;

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

    public static function combine_set($set, $primaryKey = 'id')
    {
        foreach ($set as $id => &$value) {
            $value = array_map(function ($e) {
                return is_numeric($e) ? $e : self::$db->db->quote($e);
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
                    return is_numeric($e) ? $e : self::$db->db->quote($e);
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
    }
}
