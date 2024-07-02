<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module;

use Exception;
use KeepersTeam\Webtlo\DTO\ForumObject;
use KeepersTeam\Webtlo\Legacy\Db;

/**
 * Работа с хранимыми подразделами.
 */
final class Forums
{
    /** @var ForumObject[] */
    private static array $forums = [];

    /**
     * Получить параметры заданного подраздела.
     *
     * @throws Exception
     */
    public static function getForum(int $forumId): ForumObject
    {
        $forum = self::$forums[$forumId] ?? null;
        if (null === $forum) {
            $sql = '
                SELECT f.id, f.name, f.quantity, f.size
                FROM Forums f
                WHERE f.id = ?
            ';

            $res = Db::query_database_row($sql, [$forumId], true);

            if (empty($res)) {
                throw new Exception("Error: Нет данных о хранимом подразделе № $forumId");
            }

            $forum = new ForumObject(...(array)$res);

            self::$forums[$forumId] = $forum;
        }

        return $forum;
    }

    /**
     * Получить имя заданного подраздела.
     */
    public static function getForumName(int $forumId): string
    {
        try {
            $forum = self::getForum($forumId);

            return $forum->name;
        } catch (Exception $e) {
            // TODO запись в лог.
            return '';
        }
    }
}
