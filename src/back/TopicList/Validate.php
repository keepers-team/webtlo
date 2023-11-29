<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

use Exception;

final class Validate
{
    /**
     * @throws Exception
     */
    public static function sortFilter(array $filter): void
    {
        if (!isset($filter['filter_sort'])) {
            throw new Exception('Не выбрано поле для сортировки');
        }

        if (!isset($filter['filter_sort_direction'])) {
            throw new Exception('Не выбрано направление сортировки');
        }
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
}