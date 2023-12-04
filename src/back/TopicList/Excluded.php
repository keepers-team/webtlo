<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

final class Excluded
{
    public function __construct(
        public int $count = 0,
        public int $size = 0
    ) {
    }
}