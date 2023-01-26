<?php

namespace KeepersTeam\Webtlo\External\Api\V1;

final class PeerResponse
{
    /**
     * @param PeerData[] $peers
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
