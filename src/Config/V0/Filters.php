<?php

namespace KeepersTeam\Webtlo\Config\V0;

final class Filters
{
    public function __construct(
        public readonly int $ruleTopics = 3,
        public readonly int $ruleDateRelease = 0,
        public readonly bool $avgSeeders = false,
        public readonly int $avgSeedersPeriod = 14,
        public readonly int $avgSeedersPeriodOutdated = 7,
        public readonly bool $autoApplyFilter = true,
    ) {
    }
}
