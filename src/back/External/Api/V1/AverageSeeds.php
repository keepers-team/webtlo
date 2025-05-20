<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\V1;

final class AverageSeeds
{
    /**
     * @param int   $sum          сумма сидов за сегодня
     * @param int   $count        количество обновлений за сегодня
     * @param int[] $sumHistory   история сумм сидов за предыдущие 30 дней
     * @param int[] $countHistory история количества обновлений за предыдущие 30 дней
     */
    public function __construct(
        public readonly int   $sum,
        public readonly int   $count,
        public readonly array $sumHistory,
        public readonly array $countHistory,
    ) {}
}
