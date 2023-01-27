<?php

namespace KeepersTeam\Webtlo\External\Api\V1;

use DateTimeImmutable;

final class ForumTopicsResponse
{
    public function __construct(
        public readonly DateTimeImmutable $updateTime,
        public readonly int $totalSize,
        /** @var ForumTopicsData[] */
        public readonly array $topics,
    ) {
    }
}
