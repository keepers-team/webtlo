<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Filter;

final class KeepersCount
{
    public function __construct(
        public readonly bool $enabled,
        public readonly bool $useSeed,
        public readonly bool $useDownload,
        public readonly bool $useKept,
        public readonly bool $useKeptSeed,
        public readonly int  $min,
        public readonly int  $max,
    ) {
    }
}