<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage\Clone;

use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\External\Api\V1\KeeperData;
use KeepersTeam\Webtlo\External\ApiReport\V1\KeptTopic;
use KeepersTeam\Webtlo\Storage\CloneTable;
use Psr\Log\LoggerInterface;

/**
 * Временная таблица содержащая данные о хранителях и их хранимых раздачах, по данным API отчётов.
 */
final class KeepersLists
{
    // Параметры таблицы.
    public const TABLE   = 'KeepersLists';
    public const PRIMARY = 'topic_id';
    public const KEYS    = [
        self::PRIMARY,
        'keeper_id',
        'keeper_name',
        'posted',
        'complete',
    ];

    /** @var array<int, mixed>[] */
    private array $keptTopics = [];

    public function __construct(
        private readonly DB              $db,
        private readonly LoggerInterface $logger,
        private readonly CloneTable      $clone,
    ) {}

    /**
     * @param KeeperData  $keeper
     * @param KeptTopic[] $topics
     * @return void
     */
    public function addKeptTopics(KeeperData $keeper, array $topics): void
    {
        foreach ($topics as $topic) {
            $this->keptTopics[] = [
                $topic->id,
                $keeper->keeperId,
                $keeper->keeperName,
                $topic->posted->getTimestamp(),
                (int) $topic->complete,
            ];
        }
    }

    /**
     * Записать часть раздач во временную таблицу.
     */
    public function fillTempTable(): void
    {
        $tab = $this->clone;

        $rows = array_map(fn($el) => array_combine($tab->getTableKeys(), $el), $this->keptTopics);
        $tab->cloneFillChunk($rows, 200);

        $this->keptTopics = [];
    }

    /**
     * Перенести данные о хранимых раздачах в основную таблицу БД.
     */
    public function moveToOrigin(int $forumsScanned, int $keepersCount): void
    {
        $tab = $this->clone;

        $keepersSeedersCount = $tab->cloneCount();
        if ($keepersSeedersCount > 0) {
            $this->logger->info('Подразделов: {forums} шт, хранителей: {keepers}, хранимых раздач: {topics} шт.', [
                'forums'  => $forumsScanned,
                'keepers' => $keepersCount,
                'topics'  => $keepersSeedersCount,
            ]);
            $this->logger->info('Запись в базу данных списков раздач хранителей...');

            $tab->moveToOrigin();

            // Удаляем неактуальные записи списков.
            $tab->removeUnusedKeepersRows();

            $this->logger->info('Записано {topics} хранимых раздач.', ['topics' => $keepersSeedersCount]);
        }
    }

    public function clearLists(): void
    {
        $this->db->executeStatement('DELETE FROM UpdateTime WHERE id BETWEEN 100000 AND 200000');
    }
}
