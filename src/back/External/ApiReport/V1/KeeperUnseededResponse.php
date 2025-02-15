<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\V1;

use DateTimeImmutable;
use Throwable;

final class KeeperUnseededResponse
{
    /**
     * @param int                 $subForumId Ид подраздела
     * @param int                 $totalCount Количество хранимых раздач в подразделе
     * @param string[]            $columns
     * @param array<int, mixed>[] $releases
     * @param DateTimeImmutable   $cacheTime  Дата хеширования ответа
     */
    public function __construct(
        public readonly int               $subForumId,
        public readonly int               $totalCount,
        public readonly DateTimeImmutable $cacheTime,
        private readonly array            $columns,
        private readonly array            $releases,
    ) {}

    /**
     * Найти хеши раздач, которые не сидировались более заданного количества дней.
     *
     * @return string[]
     */
    public function getHashes(int $notSeedingDays): array
    {
        $cutoffDate = self::calculateCutoffDate($notSeedingDays);

        $hashes = [];
        foreach ($this->releases as $release) {
            $topic = array_combine($this->columns, $release);

            // Если даты нет, значит её очень давно не сидировали, добавляем в список.
            if ($topic['last_seeded_time'] === null) {
                $hashes[] = $topic['info_hash'];

                continue;
            }

            try {
                $lastSeeded = new DateTimeImmutable($topic['last_seeded_time']);
            } catch (Throwable) {
                // Пропускаем некорректные даты.
                continue;
            }

            // Если дата последнего сидирования меньше(старше) даты отсечки, добавляем в список.
            if ($lastSeeded < $cutoffDate) {
                $hashes[] = $topic['info_hash'];
            }
        }

        return array_map('strval', $hashes);
    }

    /**
     * Рассчитывает дату отсечки на основе количества дней.
     *
     * @param int $days количество дней для отсечки
     *
     * @return DateTimeImmutable дата отсечки
     */
    private static function calculateCutoffDate(int $days): DateTimeImmutable
    {
        return (new DateTimeImmutable("-$days days"))->setTime(0, 0);
    }
}
