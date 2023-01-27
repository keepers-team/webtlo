<?php

namespace KeepersTeam\Webtlo\External\Api\V1;

use DateTimeImmutable;

final class HighPriorityTopicsResponse
{
    public function __construct(
        public readonly DateTimeImmutable $updateTime,
        /** @var HighPriorityTopic[] */
        public readonly array $topics,
    ) {
    }
}
