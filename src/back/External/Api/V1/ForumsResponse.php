<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\V1;

use DateTimeImmutable;

/** Список подразделов. */
final class ForumsResponse
{
    public function __construct(
        public readonly DateTimeImmutable $updateTime,
        /** @var ForumDetails[] */
        public readonly array             $forums,
    ) {}
}
