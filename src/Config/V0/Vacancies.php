<?php

namespace KeepersTeam\Webtlo\Config\V0;

final class Vacancies
{
    final public const DAY = 60 * 60 * 24;
    public function __construct(
        public readonly ?int $sendPostId = null,
        public readonly ?int $sendTopicId = null,
        public readonly bool $scanReports = false,
        public readonly int $scanPostedDays = 30,
        public readonly int $avgSeedersPeriod = 14,
        public readonly float $avgSeedersValue = 0.5,
        public readonly int $regTimeSeconds = 30 * self::DAY,
        /** @var int[] */
        public readonly array $excludeForumsIds = [],
        /** @var int[] */
        public readonly array $includeForumsIds = [],
    ) {
    }
}
