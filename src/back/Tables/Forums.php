<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Tables;

use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\DTO\ForumObject;

final class Forums
{
    /** @var ForumObject[] */
    private static array $forums = [];

    public function __construct(private readonly DB $db)
    {
    }

    /**
     * Получить параметры заданного подраздела.
     */
    public function getForum(int $forumId): ?ForumObject
    {
        $forum = self::$forums[$forumId] ?? null;

        if (null === $forum) {
            $sql = '
                SELECT f.id, f.name, f.quantity, f.size
                FROM Forums f
                WHERE f.id = :forum_id
            ';

            $res = $this->db->queryRow($sql, ['forum_id' => $forumId]);

            if (empty($res)) {
                return null;
            }

            $forum = new ForumObject(
                id      : (int)$res['id'],
                name    : (string)$res['name'],
                quantity: (int)$res['quantity'],
                size    : (int)$res['size'],
            );

            self::$forums[$forumId] = $forum;
        }

        return $forum;
    }

    /**
     * Получить имя заданного подраздела.
     */
    public function getForumName(?int $forumId): string
    {
        if (null === $forumId) {
            return '';
        }

        $forum = self::getForum($forumId);

        return $forum->name ?? '';
    }
}
