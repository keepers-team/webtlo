<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\V1;

use DateTimeImmutable;

final class KeptTopic
{
    public function __construct(
        public readonly int               $id,
        public readonly DateTimeImmutable $posted,
        public readonly bool              $complete,
    ) {
    }
}
