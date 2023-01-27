<?php

namespace KeepersTeam\Webtlo\External\Api\V1;

use DateTimeImmutable;

final class KeepersResponse
{
    public function __construct(
        public readonly DateTimeImmutable $updateTime,
        /** @var string[] */
        public readonly array $keepers,
    ) {
    }
}
