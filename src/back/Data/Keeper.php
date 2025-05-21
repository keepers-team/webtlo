<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Data;

/** Данные хранителя. */
final class Keeper
{
    public function __construct(
        public readonly int    $keeperId,
        public readonly string $keeperName,
        public readonly bool   $isCandidate
    ) {}
}
