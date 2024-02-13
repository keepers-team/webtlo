<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\DTO;

/** Данные об обновлениях раздачи. */
final class TopicAverage
{
    public function __construct(
        public readonly int $daysUpdate = 0,
        public readonly int $sumUpdates = 1,
        public readonly int $sumSeeders = 0
    ) {
    }
}
