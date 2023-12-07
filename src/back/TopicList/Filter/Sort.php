<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Filter;

final class Sort
{
    public function __construct(
        public readonly SortRule      $rule,
        public readonly SortDirection $direction,
    ) {
    }
}