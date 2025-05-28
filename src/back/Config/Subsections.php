<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

/**
 * Все подразделы, выбранные хранимыми.
 */
final class Subsections
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

    /**
     * Найти значение лимита пиров для регулировки по ид подраздела.
     */
    public function getControlPeers(int $subForumId): int
    {
        return $this->params[$subForumId]->controlPeers ?? -2;
    }
}
