<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\DTO;

final class UpdateDetailsResultObject
{
    public function __construct(
        public int       $before,
        public int       $after,
        public int       $perRun,
        public int       $runs,
        public int       $execFull,
        public int|float $execAverage
    ) {
    }
}

