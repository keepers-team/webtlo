<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

use KeepersTeam\Webtlo\TopicList\Filter\AverageSeed;
use KeepersTeam\Webtlo\TopicList\Filter\KeepersCount;
use KeepersTeam\Webtlo\TopicList\Filter\Seed;
use KeepersTeam\Webtlo\TopicList\Filter\SeedComparison;
use KeepersTeam\Webtlo\TopicList\Filter\Strings;

/** Фильтрация полученного списка раздач. */
final class FilterApply
{
    /**
     * Попадает ли количество хранителей раздачи в заданные пределы по заданным правилам.
     *
     * @param array<string, mixed>[] $topicKeepers
     */
    public static function isTopicKeepersInRange(KeepersCount $countRules, array $topicKeepers): bool
    {
        if (!$countRules->enabled) {
            return true;
        }

        $matchedKeepers = array_filter(
            $topicKeepers,
            function($kp) use ($countRules) {
                // Хранитель раздаёт.
                if ($countRules->useSeed && $kp['seeding'] === 1) {
                    return true;
                }
                // Хранитель качает.
                if ($countRules->useDownload && $kp['complete'] < 1) {
                    return true;
                }
                // Хранитель хранит, не раздаёт.
                if ($countRules->useKept && $kp['complete'] === 1 && $kp['posted'] > 0 && $kp['seeding'] === 0) {
                    return true;
                }
                // Хранитель хранит и раздаёт.
                if ($countRules->useKeptSeed && $kp['complete'] === 1 && $kp['posted'] > 0 && $kp['seeding'] === 1) {
                    return true;
                }

                return false;
            }
        );

        $keepersCount = count($matchedKeepers);

        return $countRules->min <= $keepersCount && $keepersCount <= $countRules->max;
    }

    /** Попадает ли количество сидов раздачи в заданные пределы. */
    public static function isSeedCountInRange(Seed $range, float $topicSeeds): bool
    {
        if ($range->comparisonType === SeedComparison::INTERVAL) {
            return $range->min <= $topicSeeds && $topicSeeds <= $range->max;
        }

        if ($range->comparisonType === SeedComparison::NO_MORE) {
            return $topicSeeds <= $range->value;
        }

        return $topicSeeds >= $range->value;
    }

    /** Количество дней обновления сидов "полное". */
    public static function isSeedCountGreen(AverageSeed $seedPeriod, int $ds): bool
    {
        return !$seedPeriod->checkGreen || $ds >= $seedPeriod->seedPeriod;
    }

    /**
     * Есть ли пользователь среди хранителей.
     *
     * @param array<string, mixed>[] $topicKeepers
     */
    public static function isUserInKeepers(array $topicKeepers, int $userId): bool
    {
        $keepersList = array_column($topicKeepers, 'keeper_id');

        return count($keepersList) && in_array($userId, $keepersList);
    }

    /**
     * Фильтрация по текстовому полю.
     *
     * @param array<string, mixed>[] $topicKeepers
     */
    public static function isStringsMatch(Strings $filterStrings, Topic $topic, array $topicKeepers): bool
    {
        if (!$filterStrings->enabled) {
            return true;
        }

        if ($filterStrings->type === 0) {
            // В имени хранителя.
            $topicKeepers = array_column($topicKeepers, 'keeper_name');

            $matchKeepers = [];
            foreach ($filterStrings->values as $filterKeeper) {
                if (mb_substr($filterKeeper, 0, 1) === '!') {
                    $matchKeepers[] = !in_array(mb_substr($filterKeeper, 1), $topicKeepers);
                } else {
                    $matchKeepers[] = in_array($filterKeeper, $topicKeepers);
                }
            }
            if (in_array(0, $matchKeepers)) {
                return false;
            }
        } elseif ($filterStrings->type === 1) {
            // В названии раздачи.
            $filterCount = count($filterStrings->values);

            $matched = [];
            foreach ($filterStrings->values as $filter) {
                $filter = str_replace('\*', '.', $filter);

                if (mb_substr($filter, 0, 1) === '!') {
                    $matched[] = !mb_eregi(mb_substr($filter, 1), $topic->name);
                } else {
                    $matched[] = mb_eregi($filter, $topic->name);
                }
            }

            if (count(array_filter($matched)) !== $filterCount) {
                return false;
            }
        } elseif ($filterStrings->type === 2) {
            // В номере/ид раздачи.
            $matchId = false;
            foreach ($filterStrings->values as $filterId) {
                $filterId = sprintf('^%s$', str_replace('*', '.*', $filterId));
                if (mb_eregi($filterId, (string) $topic->id)) {
                    $matchId = true;
                }
            }
            if (!$matchId) {
                return false;
            }
        }

        return true;
    }
}
