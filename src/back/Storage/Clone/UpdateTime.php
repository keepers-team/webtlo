<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage\Clone;

use DateTimeImmutable;
use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Storage\CloneTable;

/**
 * Временная таблица с датами последних обновлений различных модулей/подразделов.
 */
final class UpdateTime
{
    // Параметры таблицы.
    public const TABLE   = 'UpdateTime';
    public const PRIMARY = 'id';
    public const KEYS    = [
        self::PRIMARY,
        'ud',
    ];

    /** @var array<int, int>[] */
    private array $updatedMarkers = [];

    public function __construct(
        private readonly DB         $db,
        private readonly CloneTable $clone,
    ) {}

    /**
     * Получить timestamp с датой обновления.
     */
    public function getMarkerTimestamp(int|UpdateMark $marker): int
    {
        if ($marker instanceof UpdateMark) {
            $marker = $marker->value;
        }

        return (int) $this->db->queryColumn(
            'SELECT ud FROM UpdateTime WHERE id = ?',
            [$marker],
        );
    }

    /**
     * Получить объект с датой обновления.
     */
    public function getMarkerTime(int|UpdateMark $marker): DateTimeImmutable
    {
        return Helper::makeDateTime($this->getMarkerTimestamp($marker));
    }

    /**
     * Добавить данные об обновлении маркера.
     */
    public function addMarkerUpdate(
        int|UpdateMark        $marker,
        int|DateTimeImmutable $updateTime = new DateTimeImmutable()
    ): void {
        if ($marker instanceof UpdateMark) {
            $marker = $marker->value;
        }

        if ($updateTime instanceof DateTimeImmutable) {
            $updateTime = $updateTime->getTimestamp();
        }

        $this->updatedMarkers[] = [$marker, $updateTime];
    }

    /**
     * Перенести данные о хранимых раздачах в основную таблицу БД.
     */
    public function moveToOrigin(): void
    {
        if (!count($this->updatedMarkers)) {
            return;
        }

        $rows = array_map(fn($el) => array_combine($this->clone->getTableKeys(), $el), $this->updatedMarkers);

        $this->clone->cloneFillChunk(dataSet: $rows);

        $this->clone->moveToOrigin();
    }
}
