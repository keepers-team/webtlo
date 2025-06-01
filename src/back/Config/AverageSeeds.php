<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

/**
 * Опции для расчета среднего количества сидов раздачи.
 */
final class AverageSeeds
{
    /**
     * @param bool $enableHistory     включить сбор истории
     * @param int  $historyDays       дней для сбора данных
     * @param int  $historyExpiryDays дней до очистки устаревших данных
     */
    public function __construct(
        public readonly bool $enableHistory,
        public readonly int  $historyDays,
        public readonly int  $historyExpiryDays,
    ) {}
}
