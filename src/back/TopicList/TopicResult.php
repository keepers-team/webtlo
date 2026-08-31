<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

final class TopicResult
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly Topic $topic,
        public readonly array $details = [],
    ) {}
}
