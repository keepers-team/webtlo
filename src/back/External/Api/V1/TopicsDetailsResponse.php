<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\V1;

/** Дополнительные сведения о раздачах. */
final class TopicsDetailsResponse
{
    /**
     * @param TopicDetails[] $actualTopics
     * @param TopicDetails[] $oldTopics
     * @param (int|string)[] $missingTopics
     */
    public function __construct(
        public readonly array $actualTopics,
        public readonly array $oldTopics = [],
        public readonly array $missingTopics = []
    ) {}
}
