<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage\Clone;

use KeepersTeam\Webtlo\External\Api\V1\KeeperData;
use KeepersTeam\Webtlo\External\Api\V1\KeepersResponse;
use KeepersTeam\Webtlo\External\ApiReport\V1\KeptTopic;
use KeepersTeam\Webtlo\Storage\CloneTable;
use KeepersTeam\Webtlo\Update\ExcludedKeepersTrait;
use Psr\Log\LoggerInterface;

/**
 * Временная таблица содержащая данные о хранителях и сидируемых ими раздачах, по данным API форума.
 */
final class KeepersSeeders
{
    use ExcludedKeepersTrait;

    // Параметры таблицы.
    public const TABLE   = 'KeepersSeeders';
    public const PRIMARY = 'topic_id';
    public const KEYS    = [
        self::PRIMARY,
        'keeper_id',
        'keeper_name',
    ];

    /** @var array<int, mixed>[] */
    private array $keptTopics = [];

    /** Данные о хранителях. */
    private KeepersResponse $keepers;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CloneTable      $clone,
    ) {}

    public function withKeepers(KeepersResponse $keepers): void
    {
        $this->keepers = $keepers;
    }

    /**
     * @param int[] $topicKeepers
     */
    public function addKeptTopic(int $topicId, array $topicKeepers): void
    {
        foreach ($topicKeepers as $keeperId) {
            // Пропускаем игнорируемых хранителей.
            if (in_array($keeperId, $this->excludedKeepers, true)) {
                continue;
            }

            $keeper = $this->keepers->getKeeperInfo(keeperId: $keeperId);
            if ($keeper !== null) {
                $this->keptTopics[] = [$topicId, $keeper->keeperId, $keeper->keeperName];
            }
        }
    }

    /**
     * Записать хранителя, если он сидирует раздачу.
     *
     * @param KeptTopic[] $topics
     */
    public function addKeptTopics(KeeperData $keeper, array $topics): void
    {
        foreach ($topics as $topic) {
            // Исключаем не сидируемые раздачи хранителя.
            if (!$topic->seeding) {
                continue;
            }

            $this->keptTopics[] = [
                $topic->id,
                $keeper->keeperId,
                $keeper->keeperName,
            ];
        }
    }

    /**
     * Записать часть раздач во временную таблицу.
     */
    public function cloneFill(): void
    {
        $tab = $this->clone;

        $rows = array_map(fn($el) => array_combine($tab->getTableKeys(), $el), $this->keptTopics);
        $tab->cloneFillChunk($rows);

        $this->keptTopics = [];
    }

    /**
     * Перенести данные о хранимых раздачах в основную таблицу БД.
     */
    public function moveToOrigin(): void
    {
        $tab = $this->clone;

        $keepersSeedersCount = $tab->cloneCount();
        if ($keepersSeedersCount > 0) {
            $this->logger->info('KeepersSeeders. Запись в базу данных списка сидов-хранителей...');
            $tab->moveToOrigin();

            // Удалить ненужные записи.
            $tab->removeUnusedKeepersRows();

            $this->logger->info(
                'KeepersSeeders. Хранителями раздаётся {topics} неуникальных раздач.',
                ['topics' => $keepersSeedersCount]
            );
        }
    }
}
