<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Filter;

final class AverageSeed
{
    public function __construct(
        public readonly bool  $enabled,
        public readonly bool  $checkGreen,
        public readonly int   $seedPeriod,
        /** @var string[] */
        public readonly array $fields,
        /** @var string[] */
        public readonly array $joins
    ) {
    }

    public function getFields(): string
    {
        return implode(',', $this->fields);
    }

    public function getJoins(): string
    {
        return implode(' ', $this->joins);
    }
}
