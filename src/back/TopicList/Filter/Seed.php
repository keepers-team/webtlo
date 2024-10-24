<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Filter;

/** Фильтр по количеству сидов раздачи. */
final class Seed
{
    public function __construct(
        public readonly SeedComparison $comparisonType,
        public readonly float          $value = 3,
        public readonly float          $min = 1,
        public readonly float          $max = 10,
    ) {}
}
