<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage\Clone;

use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\Storage\CloneTable;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Временная таблица с разрегистрированными или обновлёнными раздачами.
 */
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

    /** @var array<int, mixed>[] */
    private array $topics = [];

    public function __construct(
        private readonly DB              $db,
        private readonly LoggerInterface $logger,
        private readonly CloneTable      $clone,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function searchUnregisteredTopics(): array
    {
        return $this->db->query(
            sql  : "
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
            pdo  : PDO::FETCH_KEY_PAIR
        );
    }

    /**
     * @param array<int, mixed> $topic
     * @return void
     */
    public function addTopic(array $topic): void
    {
        $this->topics[] = $topic;
    }

    /**
     * Записать раздачи во временную таблицу.
     */
    public function fillTempTable(): void
    {
        $rows = array_map(fn($el) => array_combine($this->clone->getTableKeys(), $el), $this->topics);

        $this->clone->cloneFillChunk(dataSet: $rows);

        $this->topics = [];
    }

    /**
     * Перенести данные о раздачах в основную таблицу БД.
     */
    public function moveToOrigin(): void
    {
        $count = $this->clone->cloneCount();
        if ($count > 0) {
            $this->logger->info('Найдено разрегистрированных или обновлённых раздач: {count} шт.', ['count' => $count]);
            $this->clone->moveToOrigin();
        }
    }

    /**
     * Удаление лишних раздач из таблицы разрегистрированных.
     */
    public function clearUnusedRows(): void
    {
        $this->clone->clearUnusedRows();
    }
}
