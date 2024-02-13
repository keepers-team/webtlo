<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\V1;

use DateTimeImmutable;

/** Дополнительные сведения о раздаче. */
final class TopicDetails
{
    public function __construct(
        public readonly int               $id,
        public readonly string            $hash,
        public readonly int               $forumId,
        public readonly int               $poster,
        public readonly int               $size,
        public readonly DateTimeImmutable $registered,
        public readonly TorrentStatus     $status,
        public readonly int               $seeders,
        public readonly string            $title,
        public readonly DateTimeImmutable $lastSeeded,
        public readonly int               $downloads,
    ) {
    }
}
