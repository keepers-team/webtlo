<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

/**
 * Параметры из конфига, которые влияют на поиск раздач в БД.
 */
final class ConfigFilter
{
    /**
     * @param positive-int[] $showedSubForums отображаемые хранимые подразделы
     * @param positive-int[] $hiddenSubForums скрытые хранимые подразделы
     */
    public function __construct(
        public readonly int   $userId,
        public readonly bool  $excludeSelf,
        public readonly bool  $enableAverageHistory,
        public readonly array $showedSubForums,
        public readonly array $hiddenSubForums,
    ) {}
}
