<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

use DateTimeImmutable;
use KeepersTeam\Webtlo\TopicList\Filter\AverageSeed;
use KeepersTeam\Webtlo\TopicList\Filter\Keepers;
use KeepersTeam\Webtlo\TopicList\Filter\KeepersCount;
use KeepersTeam\Webtlo\TopicList\Filter\KeptStatus;
use KeepersTeam\Webtlo\TopicList\Filter\Seed;
use KeepersTeam\Webtlo\TopicList\Filter\SeedComparison;
use KeepersTeam\Webtlo\TopicList\Filter\Sort;
use KeepersTeam\Webtlo\TopicList\Filter\SortDirection;
use KeepersTeam\Webtlo\TopicList\Filter\SortRule;
use KeepersTeam\Webtlo\TopicList\Filter\Strings;

final class Validate
{
    /**
     * @param array<string, mixed> $filter
     *
     * @throws ValidationException
     */
    public static function sortFilter(array $filter): Sort
    {
        $sortRule = SortRule::tryFrom((string) ($filter['filter_sort'] ?? null));
        if ($sortRule === null) {
            throw new ValidationException(
                'Не выбрано или неизвестное поле для сортировки.',
                'filter-exception-sort-rule'
            );
        }

        $sortDirection = SortDirection::tryFrom((int) ($filter['filter_sort_direction'] ?? null));
        if ($sortDirection === null) {
            throw new ValidationException(
                'Не выбрано или неизвестное направление сортировки.',
                'filter-exception-sort-direction'
            );
        }

        return new Sort($sortRule, $sortDirection);
    }

    /**
     * Проверим ввод значения сидов или количества хранителей.
     *
     * @param array<string, mixed> $filter
     *
     * @throws ValidationException
     */
    public static function filterRuleIntervals(array $filter): void
    {
        $makeException = function(string $hint, string $type, string $class = ''): void {
            $patterns = [
                'invalid' => 'В фильтре введено некорректное значение %s.',
                'zero'    => 'Значение %s в фильтре должно быть больше 0.',
                'minmax'  => 'Максимальное значение %s в фильтре должно быть больше минимального.',
            ];

            $error = sprintf($patterns[$type] ?? '%s', $hint);

            throw new ValidationException($error, $class);
        };

        // Проверки для значения количества сидов.
        if (!is_numeric($filter['filter_rule'])) {
            $makeException('сидов', 'invalid', 'filter-exception-seed-one');
        }
        if ($filter['filter_rule'] < 0) {
            $makeException('сидов', 'zero', 'filter-exception-seed-one');
        }

        // Для диапазонов свои проверки.
        $filterRules = [
            [
                'name'  => 'filter_rule_interval',
                'hint'  => 'интервала сидов',
                'class' => 'filter-exception-seed-interval',
            ],
            [
                'name'  => 'keepers_filter_count',
                'hint'  => 'количества хранителей',
                'class' => 'filter-exception-keepers-count',
            ],
        ];
        foreach ($filterRules as $rule) {
            if (
                !is_numeric($filter[$rule['name']]['min'])
                || !is_numeric($filter[$rule['name']]['max'])
            ) {
                $makeException($rule['hint'], 'invalid', $rule['class']);
            }

            if ($filter[$rule['name']]['min'] < 0 || $filter[$rule['name']]['max'] < 0) {
                $makeException($rule['hint'], 'zero', $rule['class']);
            }

            if ($filter[$rule['name']]['min'] > $filter[$rule['name']]['max']) {
                $makeException($rule['hint'], 'minmax', $rule['class']);
            }
        }
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return int[]
     *
     * @throws ValidationException
     */
    public static function checkTrackerStatus(array $filter): array
    {
        if (empty($filter['filter_tracker_status'])) {
            throw new ValidationException('Не выбраны статусы раздач для трекера.', 'filter-exception-tracker-status');
        }

        return array_map('intval', (array) $filter['filter_tracker_status']);
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @throws ValidationException
     */
    public static function checkClientStatus(array $filter): void
    {
        if (empty($filter['filter_client_status'])) {
            throw new ValidationException(
                'Не выбраны статусы раздач для торрент-клиента.',
                'filter-exception-client-status'
            );
        }
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return int[]
     *
     * @throws ValidationException
     */
    public static function checkKeepingPriority(array $filter, int $forumId): array
    {
        if (empty($filter['keeping_priority'])) {
            if ($forumId === -5) {
                return [2];
            }

            throw new ValidationException(
                'Не выбраны приоритеты раздач для трекера.',
                'filter-exception-tracker-priority'
            );
        }

        return array_map('intval', (array) $filter['keeping_priority']);
    }

    /**
     * @param array<string, mixed> $filter
     */
    public static function getDateRelease(array $filter): ?DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('d.m.Y', $filter['filter_date_release']) ?: null;
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @throws ValidationException
     */
    public static function checkDateRelease(array $filter): DateTimeImmutable
    {
        $date = self::getDateRelease($filter);
        if (!$date) {
            throw new ValidationException(
                'В фильтре введена некорректная дата создания релиза.',
                'filter-exception-date-release'
            );
        }

        return $date;
    }

    /**
     * Собрать параметры для работы со средними сидами.
     *
     * @param array<string, mixed> $filter
     *
     * @throws ValidationException
     */
    public static function prepareAverageSeedFilter(array $filter, bool $averageEnabled): AverageSeed
    {
        $greenSeeders = (bool) ($filter['avg_seeders_complete'] ?? false);

        // Жёсткое ограничение от 1 до 30 дней для средних сидов.
        $seedPeriod = min(max((int) $filter['avg_seeders_period'], 1), 30);

        // Расчёт СС отключён в настройках.
        if (!$averageEnabled) {
            $fields = [
                '-1 AS days_seed',
                'Topics.seeders * 1. / MAX(1, Topics.seeders_updates_today) AS seed',
            ];

            return new AverageSeed(
                enabled   : false,
                checkGreen: $greenSeeders,
                seedPeriod: 1,
                fields    : $fields,
                joins     : []
            );
        }

        // Валидация периода средних сидов.
        if (!is_numeric($filter['avg_seeders_period'] ?? null)) {
            throw new ValidationException(
                'В фильтре введено некорректное значение для периода средних сидов.',
                'filter-exception-seeders-period'
            );
        }

        // Для выполнения расчёта за один день таблица Seeders не нужна.
        if ($seedPeriod === 1) {
            $fields = [
                '1 AS days_seed',
                'Topics.seeders * 1. / MAX(1, Topics.seeders_updates_today) AS seed',
            ];

            return new AverageSeed(
                enabled   : true,
                checkGreen: $greenSeeders,
                seedPeriod: $seedPeriod,
                fields    : $fields,
                joins     : []
            );
        }

        $fields = $joins = [];
        // Применить фильтр средних сидов.
        $temp = array_fill_keys(['days_seed', 'average_sum', 'average_count'], []);

        // Сдвигаем счётчик $seedPeriod на один день, т.к. значения за "сегодня" считаются отдельно.
        for ($i = 0; $i < $seedPeriod - 1; ++$i) {
            $temp['days_seed'][]     = "CASE WHEN q$i IS '' OR q$i > 0 THEN 1 ELSE 0 END";
            $temp['average_sum'][]   = "COALESCE(d$i, 0)"; // sum - сумма измерений в заданный день.
            $temp['average_count'][] = "COALESCE(q$i, 0)"; // count - количество измерений в заданный день.
        }

        $days_seed     = implode(' + ', $temp['days_seed']);
        $average_sum   = implode(' + ', $temp['average_sum']);
        $average_count = implode(' + ', $temp['average_count']);

        $fields[] = "(1 + $days_seed) AS days_seed";
        $fields[] = "(Topics.seeders * 1. + $average_sum) / (MAX(1, Topics.seeders_updates_today) + $average_count) AS seed";

        $joins[] = 'LEFT JOIN Seeders ON Topics.id = Seeders.id';

        return new AverageSeed(
            true,
            $greenSeeders,
            $seedPeriod,
            $fields,
            $joins
        );
    }

    /**
     * Собрать параметры для фильтра по количеству сидов.
     *
     * @param array<string, mixed> $filter
     */
    public static function prepareSeedFilter(array $filter): Seed
    {
        $comparison = SeedComparison::INTERVAL;

        $useInterval = (bool) ($filter['filter_interval'] ?? false);
        if (!$useInterval) {
            $comparison = SeedComparison::from((int) $filter['filter_rule_direction']);
        }

        return new Seed(
            $comparison,
            (float) ($filter['filter_rule'] ?? 3),
            (float) ($filter['filter_rule_interval']['min'] ?? 1),
            (float) ($filter['filter_rule_interval']['max'] ?? 10)
        );
    }

    /**
     * Собрать параметры фильтрации по типам хранителей.
     *
     * @param array<string, mixed> $filter
     *
     * @throws ValidationException
     */
    public static function prepareKeepersFilter(array $filter): Keepers
    {
        $count = new KeepersCount(
            (bool) ($filter['is_keepers'] ?? false),
            (bool) ($filter['keepers_count_seed'] ?? false),
            (bool) ($filter['keepers_count_download'] ?? false),
            (bool) ($filter['keepers_count_kept'] ?? false),
            (bool) ($filter['keepers_count_kept_seed'] ?? false),
            (int) ($filter['keepers_filter_count']['min'] ?? 1),
            (int) ($filter['keepers_filter_count']['max'] ?? 10),
        );

        if ($count->enabled) {
            if (!$count->useSeed && !$count->useDownload && !$count->useKept && !$count->useKeptSeed) {
                throw new ValidationException(
                    'Не выбраны параметры фильтрации хранителей по количеству.',
                    'filter-exception-keepers-count'
                );
            }
        }

        return new Keepers(
            new KeptStatus(
                (int) ($filter['filter_status_has_keeper'] ?? -1),
                (int) ($filter['filter_status_has_seeder'] ?? -1),
                (int) ($filter['filter_status_has_downloader'] ?? -1),
            ),
            $count,
        );
    }

    /**
     * Фильтр раздач по статусу хранения.
     *
     * @return string[]
     */
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

    /**
     * Фильтр по текстовой строке.
     *
     * @param array<string, mixed> $filter
     */
    public static function prepareFilterStrings(array $filter): Strings
    {
        $pattern = '';
        $values  = [];

        $filterType = (int) $filter['filter_by_phrase'];
        if (!empty($filter['filter_phrase'])) {
            // В имени хранителя.
            if ($filterType === 0) {
                // Список ников режем по запятой, убираем пробелы и заменяем спецсимволы.
                $values = explode(',', $filter['filter_phrase']);
                $values = array_filter($values);
                $values = array_map(fn($el) => htmlspecialchars(trim($el)), $values);
            }

            // В названии раздачи.
            if ($filterType === 1) {
                $pattern = (string) preg_replace(
                    '/[её]/ui',
                    '(е|ё)',
                    quotemeta($filter['filter_phrase'])
                );

                $values = explode(',', $pattern);
                $values = array_filter($values);
                $values = array_map(fn($el) => trim($el), $values);
            }

            // В номере темы.
            if ($filterType === 2) {
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
