<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\V1;

use DateTimeImmutable;

/** Данные раздачи с высоким приоритетом хранения. */
final class HighPriorityTopic
{
    public function __construct(
        public readonly int               $id,
        public readonly TorrentStatus     $status,
        public readonly int               $seeders,
        public readonly DateTimeImmutable $registered,
        public readonly int               $size,
        public readonly int               $forumId,
    ) {}
}
