<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Tables;

use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\External\Api\V1\KeeperData;
use KeepersTeam\Webtlo\External\ApiReport\V1\KeptTopic;
use KeepersTeam\Webtlo\Module\CloneTable;
use Psr\Log\LoggerInterface;

/**
 * Таблица раздач, хранимых другими хранителями, согласно отчётам.
 */
final class KeepersLists
{
    // Параметры таблицы
    private const TABLE   = 'KeepersLists';
    private const PRIMARY = 'topic_id';
    private const KEYS    = [
        self::PRIMARY,
        'keeper_id',
        'keeper_name',
        'posted',
        'complete',
    ];

    private ?CloneTable $table = null;

    /** @var array<string, mixed>[] */
    private array $keptTopics = [];

    public function __construct(
        private readonly DB              $db,
        private readonly LoggerInterface $logger
    ) {
    }

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
                (int)$topic->complete,
            ];
        }
    }

    public function addKeptTopic(int $topicId, int $keeperId, string $keeperName, int $posted, int $complete): void
    {
        $this->keptTopics[] = [
            $topicId,
            $keeperId,
            $keeperName,
            $posted,
            $complete,
        ];
    }

    /**
     * Записать часть раздач во временную таблицу.
     */
    public function fillTempTable(): void
    {
        $tab = $this->initTable();

        $rows = array_map(fn($el) => array_combine($tab->keys, $el), $this->keptTopics);
        $tab->cloneFillChunk($rows, 200);

        $this->keptTopics = [];
    }

    /**
     * Перенести данные о хранимых раздачах в основную таблицу БД.
     */
    public function moveToOrigin(int $forumsScanned, int $keepersCount): void
    {
        $tab = $this->initTable();

        $keepersSeedersCount = $tab->cloneCount();
        if ($keepersSeedersCount > 0) {
            $this->logger->info(
                sprintf(
                    'Подразделов: %d шт, хранителей: %d, хранимых раздач: %d шт.',
                    $forumsScanned,
                    $keepersCount,
                    $keepersSeedersCount
                )
            );
            $this->logger->info('Запись в базу данных списков раздач хранителей...');

            $tab->moveToOrigin();

            // Удаляем неактуальные записи списков.
            $this->db->executeStatement(
                "DELETE FROM $tab->origin WHERE topic_id || keeper_id NOT IN (
                    SELECT upd.topic_id || upd.keeper_id
                    FROM $tab->clone AS tmp
                    LEFT JOIN $tab->origin AS upd ON tmp.topic_id = upd.topic_id AND tmp.keeper_id = upd.keeper_id
                    WHERE upd.topic_id IS NOT NULL
                )"
            );

            $this->logger->info(sprintf('Записано %d хранимых раздач.', $keepersSeedersCount));
        }
    }

    public function clearLists(): void
    {
        $this->db->executeStatement('DELETE FROM UpdateTime WHERE id BETWEEN 100000 AND 200000');
    }

    private function initTable(): CloneTable
    {
        if (null === $this->table) {
            $this->table = CloneTable::create(self::TABLE, self::KEYS, self::PRIMARY);
        }

        return $this->table;
    }
}
