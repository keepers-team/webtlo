<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\V1;

/** Дополнительные сведения о раздачах. */
final class TopicsDetailsResponse
{
    /**
     * @param TopicDetails[] $topics
     * @param (int|string)[] $missingTopics
     */
    public function __construct(
        public readonly array $topics,
        public readonly array $missingTopics
    ) {}
}
