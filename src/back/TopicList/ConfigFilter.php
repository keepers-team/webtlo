<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

/**
 * Параметры из конфига, которые влияют на поиск раздач в БД.
 */
final class ConfigFilter
{
    /**
     * @param int[] $notHiddenSubForums
     */
    public function __construct(
        public readonly int   $userId,
        public readonly bool  $excludeSelf,
        public readonly bool  $enableAverageHistory,
        public readonly array $notHiddenSubForums,
    ) {}
}
