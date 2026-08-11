<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Data;

use DateTimeImmutable;
use KeepersTeam\Webtlo\Data\Forum;

/**
 * Список подразделов форума.
 */
final class ForumsResponse
{
    /**
     * @param DateTimeImmutable $updateTime дата обновления данных
     * @param array<int, Forum> $forums     список подразделов на форуме
     */
    public function __construct(
        public readonly DateTimeImmutable $updateTime,
        public readonly array             $forums,
    ) {}

    /**
     * Найти подраздел по его ид в списке.
     */
    public function getForum(int $forumId): ?Forum
    {
        return $this->forums[$forumId] ?? null;
    }
}
