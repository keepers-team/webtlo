<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage\Table;

use KeepersTeam\Webtlo\Data\Forum;
use KeepersTeam\Webtlo\Infrastructure\Database\ConnectionInterface;
use KeepersTeam\Webtlo\Storage\KeysObject;

final class Forums
{
    // Параметры таблицы.
    public const TABLE   = 'Forums';
    public const PRIMARY = 'id';
    public const KEYS    = [
        self::PRIMARY,
        'name',
        'quantity',
        'size',
    ];

    /** @var array<int, Forum> */
    private static array $forums = [];

    public function __construct(private readonly ConnectionInterface $con) {}

    /**
     * Получить параметры заданного подраздела.
     */
    public function getForum(int $forumId): ?Forum
    {
        $forum = self::$forums[$forumId] ?? null;

        if ($forum === null) {
            $sql = '
                SELECT f.id, f.name, f.quantity, f.size
                FROM Forums f
                WHERE f.id = :forum_id
            ';

            $row = $this->con->queryRow($sql, ['forum_id' => $forumId]);

            if ($row === null || !count($row)) {
                return null;
            }

            $forum = new Forum(
                id   : (int) $row['id'],
                name : (string) $row['name'],
                count: (int) $row['quantity'],
                size : (int) $row['size'],
            );

            self::$forums[$forum->id] = $forum;
        }

        return $forum;
    }

    /**
     * @param int[] $forumIds
     */
    public function fillForums(array $forumIds): void
    {
        $forumKeys = KeysObject::create($forumIds);

        $sql = "
            SELECT f.id, f.name, f.quantity, f.size
            FROM Forums f
            WHERE f.id IN ($forumKeys->keys)
        ";

        $forums = $this->con->query(
            sql  : $sql,
            param: $forumKeys->values,
        );

        foreach ($forums as $row) {
            $forum = new Forum(
                id   : (int) $row['id'],
                name : (string) $row['name'],
                count: (int) $row['quantity'],
                size : (int) $row['size'],
            );

            self::$forums[$forum->id] = $forum;
        }
    }

    /**
     * Получить имя заданного подраздела.
     */
    public function getForumName(?int $forumId): string
    {
        if ($forumId === null) {
            return '';
        }

        $forum = self::getForum(forumId: $forumId);

        return $forum->name ?? '';
    }
}
