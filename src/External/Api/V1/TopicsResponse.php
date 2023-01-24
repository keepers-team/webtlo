<?php

namespace KeepersTeam\Webtlo\External\Api\V1;

final class TopicsResponse
{
    /**
     * @param TopicData[] $topics
     * @param int[] $missingTopics
     */
    public function __construct(
        public readonly array $topics,
        public readonly array $missingTopics
    ) {
    }
}
