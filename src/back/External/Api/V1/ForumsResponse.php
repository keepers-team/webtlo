<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\V1;

use DateTimeImmutable;
use KeepersTeam\Webtlo\Data\Forum;

/** Список подразделов. */
final class ForumsResponse
{
    public function __construct(
        public readonly DateTimeImmutable $updateTime,
        /** @var Forum[] */
        public readonly array             $forums,
    ) {}
}
