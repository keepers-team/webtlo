<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Filter;

final class KeptStatus
{
    public function __construct(
        public readonly int $hasKeeper,
        public readonly int $hasSeeder,
        public readonly int $hasDownloader,
    ) {
    }
}