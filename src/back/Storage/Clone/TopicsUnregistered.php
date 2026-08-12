<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage\Clone;

use KeepersTeam\Webtlo\Storage\CloneTable;
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
        private readonly LoggerInterface $logger,
        private readonly CloneTable      $clone,
    ) {}

    /**
     * @param array<int, mixed> $topic
     */
    public function addTopic(array $topic): void
    {
        $this->topics[] = $topic;
    }

    /**
     * Перенести данные о раздачах в основную таблицу БД.
     */
    public function moveToOrigin(): void
    {
        if (!count($this->topics)) {
            return;
        }

        $this->logger->info('Найдено разрегистрированных или обновлённых раздач: {count} шт.', ['count' => count($this->topics)]);

        $rows = array_map(fn($el) => array_combine($this->clone->getTableKeys(), $el), $this->topics);

        $this->clone->cloneFillChunk(dataSet: $rows);

        $this->clone->writeTable();
    }

    /**
     * Удаление лишних раздач из таблицы разрегистрированных.
     */
    public function clearUnusedRows(): void
    {
        $this->clone->clearUnusedRows();
    }
}
