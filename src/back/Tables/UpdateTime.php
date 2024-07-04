<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Tables;

use DateTimeImmutable;
use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Enum\UpdateStatus;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Module\MarkersUpdate;
use KeepersTeam\Webtlo\Module\CloneTable;
use PDO;
use Psr\Log\LoggerInterface;

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
        return Helper::makeDateTime($this->getMarkerTimestamp($markerId));
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
     * @param int[] $markers
     * @return MarkersUpdate
     */
    public function getMarkersObject(array $markers): MarkersUpdate
    {
        $mark = KeysObject::create($markers);

        $updates = $this->db->query(
            "SELECT id, ud FROM UpdateTime WHERE id IN ($mark->keys)",
            $mark->values,
            PDO::FETCH_KEY_PAIR
        );

        return new MarkersUpdate($markers, $updates);
    }

    /**
     * Проверить наличие всех нужных маркеров обновления и их актуальность.
     *
     * @param int[]           $markers
     * @param LoggerInterface $logger
     * @return ?DateTimeImmutable
     */
    public function checkReportsSendAvailable(array $markers, LoggerInterface $logger): ?DateTimeImmutable
    {
        $update = self::checkFullUpdate($markers);

        if ($update->getLastCheckStatus() === UpdateStatus::MISSED) {
            $update->addLogRecord($logger);
            $logger->error('Отправка отчётов невозможна. Данные в локальной БД неполные. Выполните полное обновление сведений.');

            return null;
        }

        if ($update->getLastCheckStatus() === UpdateStatus::EXPIRED) {
            $update->addLogRecord($logger);
            $logger->error(
                'Отправка отчётов невозможна. Данные в локальной БД устарели ({date}).',
                ['date' => $update->getMinUpdate()->format('d.m.y H:i')]
            );

            return null;
        }

        // Запишем минимальную дату обновления всех сведений.
        $this->setMarkerTime(UpdateMark::FULL_UPDATE->value, $update->getMinUpdate());

        return $update->getMinUpdate();
    }

    /**
     * Проверить минимальное значение обновления всех нужных маркеров для формирования и отправки отчётов.
     *
     * @param int[] $markers
     * @param int   $daysUpdateExpire
     * @param bool  $checkForum
     * @return MarkersUpdate
     */
    public function checkFullUpdate(array $markers, int $daysUpdateExpire = 5, bool $checkForum = true): MarkersUpdate
    {
        // Добавим важные маркеры обновлений.
        $markers[] = UpdateMark::FORUM_TREE->value;
        $markers[] = UpdateMark::SUBSECTIONS->value;
        $markers[] = UpdateMark::CLIENTS->value;

        if ($checkForum) {
            $markers[] = UpdateMark::FORUM_SCAN->value;
        }

        $update = $this->getMarkersObject($markers);
        $update->checkMarkersAbove($daysUpdateExpire * 24 * 3600);

        return $update;
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

    /**
     * Удалить неактуальные маркеры.
     */
    public function removeOutdatedRows(DateTimeImmutable $outdatedDate): void
    {
        $this->db->executeStatement('DELETE FROM UpdateTime WHERE ud < ?', [$outdatedDate->getTimestamp()]);
    }

    private function initTable(): CloneTable
    {
        if (null === $this->table) {
            $this->table = CloneTable::create(self::TABLE, self::KEYS, self::PRIMARY);
        }

        return $this->table;
    }
}
