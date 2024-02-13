<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\V1;

use DateTimeImmutable;

/** Данные всех хранителей. */
final class KeepersResponse
{
    public function __construct(
        public readonly DateTimeImmutable $updateTime,
        /** @var KeeperData[] */
        public readonly array             $keepers,
    ) {
    }
}
