<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

use KeepersTeam\Webtlo\DTO\KeysObject;
use Db;
use PDO;

final class DbHelper
{
    public static function queryStatement(string $statement, array $params = []): array
    {
        return (array)Db::query_database($statement, $params, true);
    }

    public static function queryStatementRow(string $statement, array $params = []): array
    {
        return (array)Db::query_database_row($statement, $params, true);
    }

    public static function queryStatementGroup(string $statement, array $params = []): array
    {
        return (array)Db::query_database($statement, $params, true, PDO::FETCH_ASSOC | PDO::FETCH_GROUP);
    }

    /** Список подразделов с раздачами высокого приоритета. */
    public static function getHighPriorityForums(): array
    {
        return (array)Db::query_database(
            'SELECT DISTINCT ss FROM Topics WHERE pt = 2',
            [],
            true,
            PDO::FETCH_COLUMN
        );
    }
}