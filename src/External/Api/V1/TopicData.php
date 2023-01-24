<?php

namespace KeepersTeam\Webtlo\External\Api\V1;

use DateTimeImmutable;

final class TopicData
{
    public function __construct(
        public readonly int $id,
        public readonly string $hash,
        public readonly int $forum,
        public readonly int $poster,
        public readonly int $size,
        public readonly DateTimeImmutable $registered,
        public readonly TorrentStatus $status,
        public readonly int $seeders,
        public readonly string $title,
        public readonly DateTimeImmutable $lastSeeded,
        public readonly int $downloads,
    ) {
    }
}
