<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Filter;

final class Strings
{
    public function __construct(
        public readonly bool   $enabled,
        public readonly int    $type,
        public readonly array  $values,
        public readonly string $pattern,
    ) {
    }
}