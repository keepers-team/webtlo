<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Filter;

final class Keepers
{
    public function __construct(
        public readonly KeptStatus   $status,
        public readonly KeepersCount $count,
    ) {
    }
}