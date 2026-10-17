<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage\Clone;

use KeepersTeam\Webtlo\Data\Keeper;
use KeepersTeam\Webtlo\External\Data\KeptTopic;
use KeepersTeam\Webtlo\Storage\CloneTable;

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

    public function __construct(private readonly CloneTable $clone) {}

    /**
     * @param KeptTopic[] $topics
     */
    public function addKeptTopics(Keeper $keeper, array $topics): void
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

        $rows = array_map(static fn($el) => array_combine($tab->getTableKeys(), $el), $this->keptTopics);
        $tab->cloneFillChunk($rows, 200);

        $this->keptTopics = [];
    }

    public function clearTempTable(): void
    {
        $this->clone->clearClone();
        $this->keptTopics = [];
    }

    /**
     * Заменить данные подраздела в основной таблице БД.
     */
    public function replaceForum(int $forumId): int
    {
        $count = $this->clone->cloneCount();
        $this->clone->replaceKeepersRows(forumId: $forumId);

        return $count;
    }
}
