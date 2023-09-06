<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module;

use Db;
use PDO;

final class LastUpdate
{
    /**
     * Записать timestamp последнего обновления заданного маркера.
     */
    public static function setTime(int $markerId, ?int $updateTime = null): void
    {
        $updateTime = $updateTime ?? time();
        Db::query_database(
            "INSERT INTO UpdateTime (id, ud) SELECT ?,?",
            [$markerId, $updateTime]
        );
    }

    /**
     * Получить timestamp последнего обновления заданного маркера.
     */
    public static function getTime(int $markerId): int
    {
        $updateTime = (int)Db::query_database_row(
            "SELECT ud FROM UpdateTime WHERE id = ?",
            [$markerId],
            true,
            PDO::FETCH_COLUMN
        );

        return $updateTime ?? 0;
    }

    /**
     * Проверить прошло ли заданное количество секунд с последнего обновления маркера.
     */
    public static function checkUpdateAvailable(int $markerId, int $seconds = 3600): bool
    {
        $updateTime = self::getTime($markerId);

        if (time() - $updateTime < $seconds) {
            // Если время не прошло, запретить обновление.
            return false;
        }
        return true;
    }
}