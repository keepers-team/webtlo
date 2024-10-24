<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage\Clone;

use KeepersTeam\Webtlo\Storage\CloneTable;
use KeepersTeam\Webtlo\Tables\Topics;

/**
 * Временная таблица с данными об обновлённых раздачах, по данным API форума.
 */
final class TopicsUpdate
{
    // Параметры таблицы.
    public const TABLE   = Topics::TABLE;
    public const PRIMARY = Topics::PRIMARY;
    public const KEYS    = [
        self::PRIMARY,
        'forum_id',
        'seeders',
        'status',
        'seeders_updates_today',
        'seeders_updates_days',
        'keeping_priority',
        'poster',
        'seeder_last_seen',
    ];

    /** @var array<string, int|string>[] */
    private array $topics = [];

    public function __construct(
        private readonly CloneTable $clone,
    ) {}

    /**
     * @param (int|string)[] $topic
     * @return void
     */
    public function addTopic(array $topic): void
    {
        $this->topics[] = $topic;
    }

    /**
     * Записать часть раздач во временную таблицу.
     */
    public function cloneFill(): void
    {
        if (!count($this->topics)) {
            return;
        }

        $tab = $this->clone;

        $rows = array_map(fn($el) => array_combine($tab->getTableKeys(), $el), $this->topics);
        $tab->cloneFill(dataSet: $rows);

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
