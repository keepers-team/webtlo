<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Tables;

use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\Module\CloneTable;
use PDO;
use Psr\Log\LoggerInterface;

final class TopicsUnregistered
{
    // Параметры таблицы.
    public const TABLE   = 'TopicsUnregistered';
    public const PRIMARY = 'info_hash';
    public const KEYS    = [
        self::PRIMARY,
        'name',
        'status',
        'priority',
        'transferred_from',
        'transferred_to',
        'transferred_by_whom',
    ];

    private ?CloneTable $table = null;

    /** @var array<int, mixed>[] */
    private array $topics = [];

    public function __construct(
        private readonly DB              $db,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function searchUnregisteredTopics(): array
    {
        return $this->db->query(
            "
                SELECT
                    Torrents.topic_id,
                    Torrents.info_hash
                FROM Torrents
                LEFT JOIN Topics ON Topics.info_hash = Torrents.info_hash
                LEFT JOIN TopicsUntracked ON TopicsUntracked.info_hash = Torrents.info_hash
                WHERE Topics.info_hash IS NULL
                    AND TopicsUntracked.info_hash IS NULL
                    AND Torrents.topic_id <> ''
            ",
            [],
            PDO::FETCH_KEY_PAIR
        );
    }

    /**
     * @param array<int, mixed> $topicData
     * @return void
     */
    public function addTopic(array $topicData): void
    {
        $this->topics[] = $topicData;
    }

    /**
     * Записать раздачи во временную таблицу.
     */
    public function fillTempTable(): void
    {
        $tab = $this->initTable();

        $rows = array_map(fn($el) => array_combine($tab->keys, $el), $this->topics);
        $tab->cloneFillChunk($rows);

        $this->topics = [];
    }

    /**
     * Перенести данные о раздачах в основную таблицу БД.
     */
    public function moveToOrigin(): void
    {
        $tab = $this->initTable();

        $count = $tab->cloneCount();
        if ($count > 0) {
            $this->logger->info('Найдено разрегистрированных или обновлённых раздач: {count} шт.', ['count' => $count]);
            $tab->moveToOrigin();
        }
    }

    /**
     * Удаление лишних раздач из таблицы разрегистрированных.
     */
    public function clearUnusedRows(): void
    {
        $tab = $this->initTable();
        $tab->clearUnusedRows();
    }

    private function initTable(): CloneTable
    {
        if (null === $this->table) {
            $this->table = CloneTable::create(self::TABLE, self::KEYS, self::PRIMARY);
        }

        return $this->table;
    }
}
