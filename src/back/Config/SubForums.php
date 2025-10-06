<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

use KeepersTeam\Webtlo\Storage\KeysObject;

/**
 * Все подразделы, выбранные хранимыми.
 */
final class SubForums
{
    /**
     * @param int[]                $ids    список ид подразделов
     * @param array<int, SubForum> $params параметры подразделов
     */
    public function __construct(
        public readonly array $ids,
        public readonly array $params,
    ) {}

    public function count(): int
    {
        return count($this->ids);
    }

    public function getSubForum(int $subForumId): ?SubForum
    {
        return $this->params[$subForumId] ?? null;
    }

    /**
     * Найти значение лимита пиров для регулировки по ид подраздела.
     */
    public function getControlPeers(int $subForumId): int
    {
        return $this->getSubForum(subForumId: $subForumId)->controlPeers ?? -2;
    }

    public function getKeyObject(): KeysObject
    {
        return KeysObject::create($this->ids);
    }
}
