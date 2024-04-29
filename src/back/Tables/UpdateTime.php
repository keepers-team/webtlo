<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Tables;

use DateTimeImmutable;
use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\Module\CloneTable;

/** Таблица хранения последнего времени обновления различных меток. */
final class UpdateTime
{
    // Параметры таблицы.
    public const TABLE   = 'UpdateTime';
    public const PRIMARY = 'id';
    public const KEYS    = [
        self::PRIMARY,
        'ud',
    ];

    private ?CloneTable $table = null;

    /** @var array<int, int>[] */
    private array $updatedMarkers = [];

    public function __construct(private readonly DB $db)
    {
    }

    /**
     * Получить timestamp с датой обновления.
     */
    public function getMarkerTimestamp(int $markerId): int
    {
        return (int)$this->db->queryColumn(
            "SELECT ud FROM UpdateTime WHERE id = ?",
            [$markerId],
        );
    }

    /**
     * Получить объект с датой обновления.
     */
    public function getMarkerTime(int $markerId): DateTimeImmutable
    {
        return (new DateTimeImmutable())->setTimestamp($this->getMarkerTimestamp($markerId));
    }

    /**
     * Записать новое значения даты обновления.
     */
    public function setMarkerTime(int $markerId, int|DateTimeImmutable $updateTime = new DateTimeImmutable()): void
    {
        if ($updateTime instanceof DateTimeImmutable) {
            $updateTime = $updateTime->getTimestamp();
        }
        $this->db->executeStatement(
            "INSERT INTO UpdateTime (id, ud) SELECT ?,?",
            [$markerId, $updateTime]
        );
    }

    /**
     * Проверить прошло ли достаточно времени с последнего обновления.
     */
    public function checkUpdateAvailable(int $markerId, int $seconds = 3600): bool
    {
        $updateTime = $this->getMarkerTimestamp($markerId);

        // Если не прошло заданное количество времени, обновление невозможно.
        if (time() - $updateTime < $seconds) {
            return false;
        }

        return true;
    }

    /**
     * Добавить данные об обновлении маркера.
     */
    public function addMarkerUpdate(int $markerId, int|DateTimeImmutable $updateTime = new DateTimeImmutable()): void
    {
        if ($updateTime instanceof DateTimeImmutable) {
            $updateTime = $updateTime->getTimestamp();
        }

        $this->updatedMarkers[] = [$markerId, $updateTime];
    }

    /**
     * Перенести данные о хранимых раздачах в основную таблицу БД.
     */
    public function fillTable(): void
    {
        $tab = $this->initTable();

        if (count($this->updatedMarkers) > 0) {
            $rows = array_map(fn($el) => array_combine($tab->keys, $el), $this->updatedMarkers);
            $tab->cloneFillChunk($rows);

            $tab->moveToOrigin();
        }
    }

    private function initTable(): CloneTable
    {
        if (null === $this->table) {
            $this->table = CloneTable::create(self::TABLE, self::KEYS, self::PRIMARY);
        }

        return $this->table;
    }
}
