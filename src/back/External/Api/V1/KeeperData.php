<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\V1;

/** Данные хранителя. */
final class KeeperData
{
    public function __construct(
        public readonly int    $keeperId,
        public readonly string $keeperName,
        public readonly bool   $isCandidate
    ) {}
}
