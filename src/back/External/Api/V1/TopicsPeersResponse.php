<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\V1;

use KeepersTeam\Webtlo\External\Data\TopicPeers;

/**
 * Список данных о пирах раздач.
 */
final class TopicsPeersResponse
{
    /**
     * @param TopicPeers[]   $peers
     * @param int[]|string[] $missingTopics
     *
     * @note Due to API inconsistency we've dealing
     *       with either missing topic identifiers or their hashes
     */
    public function __construct(
        public readonly array $peers,
        public readonly array $missingTopics
    ) {
    }
}
