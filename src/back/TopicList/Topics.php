<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

final class Topics
{
    public function __construct(
        public int      $count = 0,
        public int      $size = 0,
        /** @var string[] */
        public array    $list = [],
        public Excluded $excluded = new Excluded()
    ) {}

    public function mergeList(string $glue = ''): string
    {
        return implode($glue, $this->list);
    }
}
