<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage\Table;

use DateTimeImmutable;
use KeepersTeam\Webtlo\DTO\TopicAverage;

/** Таблица хранения истории количества сидов и обновлений. */
final class Seeders
{
    /**
     * Определить значения для записи истории сидов.
     *
     * @return callable(int $seeders, array<string, mixed> $previous): TopicAverage
     */
    public static function AverageProcessor(
        bool              $calcAverage,
        DateTimeImmutable $lastUpdated,
        DateTimeImmutable $updateTime
    ): callable {
        // Полночь дня последнего обновления сведений.
        $lastUpdated = $lastUpdated->setTime(0, 0);

        // Сменились ли сутки, относительно прошлого обновления сведений.
        $isDayChanged = (int) $updateTime->diff($lastUpdated)->format('%d') > 0;

        return function(int $seeders, array $previous = []) use ($calcAverage, $isDayChanged): TopicAverage {
            $daysUpdate = 0;
            $sumUpdates = 1;
            $sumSeeders = $seeders;

            if ($calcAverage) {
                $daysUpdate = $previous['seeders_updates_days'] ?? 0;
                // по прошествии дня
                if ($isDayChanged) {
                    ++$daysUpdate;
                } else {
                    $sumUpdates += $previous['seeders_updates_today'] ?? 0;
                    $sumSeeders += $previous['seeders'] ?? 0;
                }
            }

            return new TopicAverage($daysUpdate, $sumUpdates, $sumSeeders);
        };
    }
}
