<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Data;

use DateTimeImmutable;
use KeepersTeam\Webtlo\Enum\KeepingPriority;
use KeepersTeam\Webtlo\Enum\TorrentStatus;

/** Данные раздачи подраздела. */
final class ForumTopic
{
    public function __construct(
        public readonly int               $id,
        public readonly string            $hash,
        public readonly TorrentStatus     $status,
        public readonly string            $name,
        public readonly int               $forumId,
        public readonly DateTimeImmutable $registered,
        public readonly KeepingPriority   $priority,
        public readonly int               $size,
        public readonly int               $poster,
        public readonly int               $seeders,
        /** @var ?int[] */
        public readonly ?array            $keepers,
        public readonly DateTimeImmutable $lastSeeded,
        public readonly ?AverageSeeds     $averageSeeds = null,
    ) {}

    /**
     * Сумма измерений сидов за сегодня.
     */
    public function todaySeeders(): int
    {
        return $this->averageSeeds->sum ?? $this->seeders;
    }

    /**
     * Количество измерений сидов за сегодня.
     */
    public function todayUpdates(): int
    {
        return $this->averageSeeds->count ?? 1;
    }
}
