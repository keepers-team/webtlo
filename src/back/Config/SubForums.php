<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Storage\KeysObject;

/**
 * Все подразделы, выбранные хранимыми.
 */
final class SubForums
{
    /**
     * @param positive-int[]                $ids    список ид подразделов
     * @param array<positive-int, SubForum> $params параметры подразделов
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
     * Получить список подразделов, отсортированный по названию.
     *
     * @return SubForum[]
     */
    public function getNameSorted(): array
    {
        $list = $this->params;

        uasort($list, static function(SubForum $a, SubForum $b) {
            return strnatcasecmp(
                Helper::prepareCompareString($a->name),
                Helper::prepareCompareString($b->name),
            );
        });

        return $list;
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
        return $this->getSubForum(subForumId: $subForumId)->controlPeers ?? TopicControl::EmptyValue;
    }

    public function getKeyObject(): KeysObject
    {
        return KeysObject::create($this->ids);
    }
}
