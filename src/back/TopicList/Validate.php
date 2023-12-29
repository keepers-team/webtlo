<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

use KeepersTeam\Webtlo\TopicList\Filter\AverageSeed;
use KeepersTeam\Webtlo\TopicList\Filter\Keepers;
use KeepersTeam\Webtlo\TopicList\Filter\KeepersCount;
use KeepersTeam\Webtlo\TopicList\Filter\KeptStatus;
use KeepersTeam\Webtlo\TopicList\Filter\Sort;
use KeepersTeam\Webtlo\TopicList\Filter\SortDirection;
use KeepersTeam\Webtlo\TopicList\Filter\SortRule;
use KeepersTeam\Webtlo\TopicList\Filter\Strings;
use DateTimeImmutable;
use Exception;

final class Validate
{
    /**
     * @throws ValidationException
     */
    public static function sortFilter(array $filter): Sort
    {
        $sortRule = SortRule::tryFrom((string)($filter['filter_sort'] ?? null));
        if (null === $sortRule) {
            throw new ValidationException('Не выбрано или неизвестное поле для сортировки.', 'filter-sort-rule');
        }

        $sortDirection = SortDirection::tryFrom((int)($filter['filter_sort_direction'] ?? null));
        if (null === $sortDirection) {
            throw new ValidationException('Не выбрано или неизвестное направление сортировки.', 'filter-sort-direction');
        }

        return new Sort($sortRule, $sortDirection);
    }

    /**
     * Проверим ввод значения сидов или количества хранителей.
     *
     * @throws Exception
     */
    public static function filterRuleIntervals(array $filter): void
    {
        $makeException = function(string $hint, string $type): void {
            $patterns = [
                'invalid' => 'В фильтре введено некорректное значение %s.',
                'zero'    => 'Значение %s в фильтре должно быть больше 0.',
                'minmax'  => 'Максимальное значение %s в фильтре должно быть больше минимального.',
            ];

            throw new Exception(sprintf($patterns[$type] ?? '%s', $hint));
        };

        // Проверки для значения количества сидов.
        if (!is_numeric($filter['filter_rule'])) {
            $makeException('сидов', 'invalid');
        }
        if ($filter['filter_rule'] < 0) {
            $makeException('сидов', 'zero');
        }

        // Для диапазонов свои проверки.
        $filters_hints = [
            'filter_rule_interval' => 'сидов',
            'keepers_filter_count' => 'количества хранителей',
        ];
        foreach ($filters_hints as $filter_name => $hint) {
            if (
                !is_numeric($filter[$filter_name]['min'])
                || !is_numeric($filter[$filter_name]['max'])
            ) {
                $makeException($hint, 'invalid');
            }

            if ($filter[$filter_name]['min'] < 0 || $filter[$filter_name]['max'] < 0) {
                $makeException($hint, 'zero');
            }

            if ($filter[$filter_name]['min'] > $filter[$filter_name]['max']) {
                $makeException($hint, 'minmax');
            }
        }
    }

    /**
     * @throws ValidationException
     */
    public static function checkTrackerStatus(array $filter): array
    {
        if (empty($filter['filter_tracker_status'])) {
            throw new ValidationException('Не выбраны статусы раздач для трекера.', 'filter-tracker-status');
        }

        return (array)$filter['filter_tracker_status'];
    }

    /**
     * @throws ValidationException
     */
    public static function checkClientStatus(array $filter): void
    {
        if (empty($filter['filter_client_status'])) {
            throw new ValidationException('Не выбраны статусы раздач для торрент-клиента.', 'filter-client-status');
        }
    }

    /**
     * @throws Exception
     */
    public static function checkKeepingPriority(array $filter, int $forumId): array
    {
        if (empty($filter['keeping_priority'])) {
            if ($forumId === -5) {
                return [2];
            } else {
                throw new ValidationException('Не выбраны приоритеты раздач для трекера.', 'filter-tracker-priority');
            }
        }

        return (array)$filter['keeping_priority'];
    }

    public static function getDateRelease(array $filter): ?DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('d.m.Y', $filter['filter_date_release']) ?: null;
    }

    /**
     * @throws ValidationException
     */
    public static function checkDateRelease(array $filter): DateTimeImmutable
    {
        $date = self::getDateRelease($filter);
        if (!$date) {
            throw new ValidationException('В фильтре введена некорректная дата создания релиза.', 'filter-date-release');
        }

        return $date;
    }

    /**
     * Собрать параметры для работы со средними сидами.
     *
     * @throws ValidationException
     */
    public static function prepareAverageSeedFilter(array $filter, array $cfg): AverageSeed
    {
        $useAvgSeeders = (bool)($cfg['avg_seeders'] ?? false);
        $greenSeeders  = (bool)($filter['avg_seeders_complete'] ?? false);

        // Жёсткое ограничение от 1 до 30 дней для средних сидов.
        $seedPeriod = min(max((int)$filter['avg_seeders_period'], 1), 30);

        $fields = $joins = [];
        if ($useAvgSeeders) {
            // Проверка периода средних сидов.
            if (!is_numeric($filter['avg_seeders_period'])) {
                throw new ValidationException('В фильтре введено некорректное значение для периода средних сидов.', 'filter-seeders-period');
            }

            // Применить фильтр средних сидов.
            $temp = [];
            for ($i = 0; $i < $seedPeriod; $i++) {
                $temp['sum_se'][] = "CASE WHEN d$i IS '' OR d$i IS NULL THEN 0 ELSE d$i END";
                $temp['sum_qt'][] = "CASE WHEN q$i IS '' OR q$i IS NULL THEN 0 ELSE q$i END";
                $temp['qt'][]     = "CASE WHEN q$i IS '' OR q$i IS NULL THEN 0 ELSE 1 END";
            }

            $qt     = implode('+', $temp['qt']);
            $sum_qt = implode('+', $temp['sum_qt']);
            $sum_se = implode('+', $temp['sum_se']);

            $fields[] = "$qt AS days_seed";
            $fields[] = "CASE WHEN $qt IS 0 THEN (se * 1.) / qt ELSE ( se * 1. + $sum_se) / ( qt + $sum_qt) END AS seed";

            $joins[] = 'LEFT JOIN Seeders ON Topics.id = Seeders.id';
        } else {
            $fields[] = '-1 AS days_seed';
            $fields[] = 'Topics.se AS seed';
        }

        return new AverageSeed(
            $useAvgSeeders,
            $greenSeeders,
            $seedPeriod,
            $fields,
            $joins
        );
    }


    /**
     * Собрать параметры фильтрации по типам хранителей.
     *
     * @throws ValidationException
     */
    public static function prepareKeepersFilter(array $filter): Keepers
    {
        $count = new KeepersCount(
            (bool)($filter['is_keepers'] ?? false),
            (bool)($filter['keepers_count_seed'] ?? false),
            (bool)($filter['keepers_count_download'] ?? false),
            (bool)($filter['keepers_count_kept'] ?? false),
            (bool)($filter['keepers_count_kept_seed'] ?? false),
            (int)($filter['keepers_filter_count']['min'] ?? 1),
            (int)($filter['keepers_filter_count']['max'] ?? 10),
        );

        if ($count->enabled) {
            if (!$count->useSeed && !$count->useDownload && !$count->useKept && !$count->useKeptSeed) {
                throw new ValidationException('Не выбраны параметры фильтрации хранителей по количеству.', 'filter-keepers-count');
            }
        }

        return new Keepers(
            new KeptStatus(
                (int)($filter['filter_status_has_keeper'] ?? -1),
                (int)($filter['filter_status_has_seeder'] ?? -1),
                (int)($filter['filter_status_has_downloader'] ?? -1),
            ),
            $count,
        );
    }

    /** Фильтр раздач по статусу хранения. */
    public static function getKeptStatusFilter(KeptStatus $keptStatus): array
    {
        $filter = [];
        // Фильтр "Хранитель с отчётом" = "да"/"нет"
        if ($keptStatus->hasKeeper === 1) {
            $filter[] = 'AND Keepers.max_posted IS NOT NULL';
        } elseif ($keptStatus->hasKeeper === 0) {
            $filter[] = 'AND Keepers.max_posted IS NULL';
        }

        // Фильтр "Хранитель раздаёт" = "да"/"нет"
        if ($keptStatus->hasSeeder === 1) {
            $filter[] = 'AND Keepers.has_seeding = 1';
        } elseif ($keptStatus->hasSeeder === 0) {
            $filter[] = 'AND (Keepers.has_seeding = 0 OR Keepers.has_seeding IS NULL)';
        }

        // Фильтр "Хранитель скачивает" = "да"/"нет"
        if ($keptStatus->hasDownloader === 1) {
            $filter[] = 'AND Keepers.has_download = 1';
        } elseif ($keptStatus->hasDownloader === 0) {
            $filter[] = 'AND (Keepers.has_download = 0 OR Keepers.has_download IS NULL)';
        }

        return $filter;
    }

    /** Фильтр по текстовой строке. */
    public static function prepareFilterStrings(array $filter): Strings
    {
        $pattern = '';
        $values  = [];

        $filterType = (int)$filter['filter_by_phrase'];
        if (!empty($filter['filter_phrase'])) {
            // В имени хранителя.
            if (0 === $filterType) {
                // Список ников режем по запятой, убираем пробелы и заменяем спецсимволы.
                $values = explode(',', $filter['filter_phrase']);
                $values = array_filter($values);
                $values = array_map(fn($el) => htmlspecialchars(trim($el)), $values);
            }

            // В названии раздачи.
            if (1 === $filterType) {
                $pattern = preg_replace(
                    '/[её]/ui',
                    '(е|ё)',
                    quotemeta($filter['filter_phrase'])
                );

                $values = explode(',', $pattern);
                $values = array_filter($values);
                $values = array_map(fn($el) => trim($el), $values);
            }

            // В номере темы.
            if (2 === $filterType) {
                // Удалим лишние пробелы из поисковой строки.
                $values = explode(',', preg_replace('/\s+/', '', $filter['filter_phrase']));
                $values = array_filter($values);
            }
        }

        return new Strings(
            !empty($filter['filter_phrase']),
            $filterType,
            $values,
            $pattern
        );
    }
}