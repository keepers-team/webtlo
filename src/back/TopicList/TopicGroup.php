<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

final class TopicGroup
{
    /**
     * @param TopicResult[]        $topics
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly int|string $key,
        public readonly ?string    $title = null,
        public array               $topics = [],
        public readonly array      $metadata = [],
    ) {}

    /**
     * @param TopicResult[] $topics
     */
    public static function makeDefault(array $topics): self
    {
        return new self(key: 'topics', topics: $topics);
    }
}
