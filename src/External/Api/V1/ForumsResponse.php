<?php

namespace KeepersTeam\Webtlo\External\Api\V1;

use DateTimeImmutable;

final class ForumsResponse
{
    public function __construct(
        public readonly DateTimeImmutable $updateTime,
        /** @var ForumData[] */
        public readonly array $forums,
    ) {
    }
}
