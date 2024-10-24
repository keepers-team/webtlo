<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage\Clone;

use KeepersTeam\Webtlo\External\Api\V1\TopicDetails;
use KeepersTeam\Webtlo\Storage\CloneTable;
use KeepersTeam\Webtlo\Tables\Topics;
use Psr\Log\LoggerInterface;

/**
 * Временная таблица с хранимыми раздачами из не хранимых подразделов.
 */
final class TopicsUntracked
{
    // Параметры таблицы.
    public const TABLE   = 'TopicsUntracked';
    public const PRIMARY = Topics::PRIMARY;
    public const KEYS    = [
        self::PRIMARY,
        'forum_id',
        'name',
        'info_hash',
        'seeders',
        'size',
        'status',
        'reg_time',
    ];

    /** @var array<int, int|string>[] */
    private array $topics = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CloneTable      $clone,
    ) {
    }

    public function addTopic(TopicDetails $topic): void
    {
        $this->topics[] = [
            $topic->id,
            $topic->forumId,
            $topic->title,
            $topic->hash,
            $topic->seeders,
            $topic->size,
            $topic->status->value,
            $topic->registered->getTimestamp(),
        ];
    }

    /**
     * Если нашлись существующие на форуме раздачи, то записываем их в БД.
     */
    public function moveToOrigin(): void
    {
        if (!count($this->topics)) {
            return;
        }

        $this->logger->info('Записано уникальных сторонних раздач: {count} шт.', ['count' => count($this->topics)]);

        $rows = array_map(fn($el) => array_combine($this->clone->getTableKeys(), $el), $this->topics);

        $this->clone->cloneFillChunk(dataSet: $rows);

        $this->clone->writeTable();
    }

    /**
     * Удалить строки в оригинальной таблице, которых нет во временной.
     */
    public function clearUnusedRows(): void
    {
        $this->clone->clearUnusedRows();
    }
}
