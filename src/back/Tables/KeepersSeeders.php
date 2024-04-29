<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Tables;

use KeepersTeam\Webtlo\External\Api\V1\KeeperData;
use KeepersTeam\Webtlo\Legacy\Db;
use KeepersTeam\Webtlo\Module\CloneTable;
use Psr\Log\LoggerInterface;

/** Таблица раздач, которые сидируют Хранители. */
final class KeepersSeeders
{
    // Параметры таблицы
    private const TABLE   = 'KeepersSeeders';
    private const PRIMARY = 'topic_id';
    private const KEYS    = [
        self::PRIMARY,
        'keeper_id',
        'keeper_name',
    ];

    private ?CloneTable $table = null;

    /** @var array<int, mixed>[] */
    private array $keptTopics = [];

    /** @var KeeperData[] Данные о хранителях. */
    private array $keepers;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Добавить данные о хранителях.
     *
     * @param KeeperData[] $keepers
     */
    public function addKeepersInfo(array $keepers): void
    {
        $this->keepers = array_combine(
            array_map(fn($k) => $k->keeperId, $keepers),
            $keepers
        );
    }

    /**
     * @param int   $topicId
     * @param int[] $keepers
     * @return void
     */
    public function addKeptTopic(int $topicId, array $keepers): void
    {
        foreach ($keepers as $keeperId) {
            $keeper = $this->getKeeperInfo($keeperId);
            if (null !== $keeper) {
                $this->keptTopics[] = [$topicId, $keeper->keeperId, $keeper->keeperName];
            }
        }
    }

    /**
     * Записать часть раздач во временную таблицу.
     */
    public function fillTempTable(): void
    {
        $tab = $this->initTable();

        $rows = array_map(fn($el) => array_combine($tab->keys, $el), $this->keptTopics);
        $tab->cloneFillChunk($rows);

        $this->keptTopics = [];
    }

    /**
     * Перенести данные о хранимых раздачах в основную таблицу БД.
     */
    public function moveToOrigin(): void
    {
        $tab = $this->initTable();

        $keepersSeedersCount = $tab->cloneCount();
        if ($keepersSeedersCount > 0) {
            $this->logger->info('KeepersSeeders. Запись в базу данных списка сидов-хранителей...');
            $tab->moveToOrigin();

            // Удалить ненужные записи.
            Db::query_database(
                "DELETE FROM $tab->origin WHERE topic_id || keeper_id NOT IN (
                    SELECT ks.topic_id || ks.keeper_id
                    FROM $tab->clone tmp
                    LEFT JOIN $tab->origin ks ON tmp.topic_id = ks.topic_id AND tmp.keeper_id = ks.keeper_id
                    WHERE ks.topic_id IS NOT NULL
                )"
            );

            $this->logger->info(
                sprintf('KeepersSeeders. Хранителями раздаётся %d неуникальных раздач.', $keepersSeedersCount)
            );
        }
    }

    private function initTable(): CloneTable
    {
        if (null === $this->table) {
            $this->table = CloneTable::create(self::TABLE, self::KEYS, self::PRIMARY);
        }

        return $this->table;
    }

    public function getKeeperInfo(int $keeperId): ?KeeperData
    {
        return $this->keepers[$keeperId] ?? null;
    }
}
