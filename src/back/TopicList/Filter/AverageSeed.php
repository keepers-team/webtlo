<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Filter;

final class AverageSeed
{
    public function __construct(
        public readonly bool  $enabled,
        public readonly bool  $checkGreen,
        public readonly int   $seedPeriod,
        public readonly array $fields,
        public readonly array $joins
    ) {
    }
}