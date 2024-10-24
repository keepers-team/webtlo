<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage\Clone;

use KeepersTeam\Webtlo\Storage\CloneTable;
use KeepersTeam\Webtlo\Tables\Topics;

/**
 * Временная таблица с данными о новых раздачах высокого приоритета, по данным API форума.
 */
final class HighPriorityInsert
{
    // Параметры таблицы.
    public const TABLE   = Topics::TABLE;
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
        'seeders_updates_today',
        'seeders_updates_days',
        'keeping_priority',
        'poster',
        'seeder_last_seen',
    ];

    /** @var array<string, mixed>[] */
    private array $topics = [];

    public function __construct(
        private readonly CloneTable $clone,
    ) {
    }

    /**
     * @return array{}|string[]
     */
    public function getTableKeys(): array
    {
        return $this->clone->getTableKeys();
    }

    /**
     * @param array<string, mixed>[] $topics
     */
    public function addTopics(array $topics): void
    {
        $this->topics = $topics;
    }

    /**
     * Записать часть раздач во временную таблицу.
     */
    public function cloneFill(): void
    {
        if (!count($this->topics)) {
            return;
        }

        $this->clone->cloneFill(dataSet: $this->topics);

        $this->topics = [];
    }

    public function writeTable(): int
    {
        return $this->clone->writeTable();
    }

    public function querySelectPrimaryClone(): string
    {
        return $this->clone->querySelectPrimaryClone();
    }
}
