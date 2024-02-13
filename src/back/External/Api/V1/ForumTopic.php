<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\V1;

use DateTimeImmutable;

/** Данные раздачи подраздела. */
final class ForumTopic
{
    public function __construct(
        public readonly int               $id,
        public readonly string            $hash,
        public readonly TorrentStatus     $status,
        public readonly int               $forumId,
        public readonly DateTimeImmutable $registered,
        public readonly KeepingPriority   $priority,
        public readonly int               $size,
        public readonly int               $poster,
        public readonly int               $seeders,
        /** @var ?int[] */
        public readonly ?array            $keepers,
        public readonly DateTimeImmutable $lastSeeded,
    ) {
    }
}
