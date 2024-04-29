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
                SELECT f.id, f.name, f.quantity, f.size,
                       o.topic_id, o.author_id, o.author_name, o.author_post_id,
                       o.post_ids
                FROM Forums f
                    LEFT JOIN ForumsOptions o ON o.forum_id = f.id
                WHERE f.id = ?
            ';

            $res = (array)Db::query_database_row($sql, [$forumId], true);

            if (empty($res)) {
                throw new Exception("Error: Нет данных о хранимом подразделе № $forumId");
            }
            if (null !== $res['post_ids']) {
                $res['post_ids'] = json_decode($res['post_ids'], true);
            }
            $forum = new ForumObject(...$res);

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

    /**
     * Обновить список ид сообщений заданного подраздела.
     *
     * @param int   $forumId
     * @param int[] $postList
     */
    public static function updatePostList(int $forumId, array $postList): void
    {
        $sql = 'INSERT INTO ForumsOptions (forum_id, post_ids) SELECT ?,?';

        Db::query_database($sql, [$forumId, json_encode($postList)]);
    }
}
