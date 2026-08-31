<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

final class Topics
{
    /**
     * @param TopicGroup[] $groups
     */
    public function __construct(
        public readonly array    $groups = [],
        public readonly Excluded $excluded = new Excluded(),
    ) {}
}
