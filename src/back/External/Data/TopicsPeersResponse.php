<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Data;

use KeepersTeam\Webtlo\External\Contract\TopicPeersProcessorInterface;

/**
 * Список данных о пирах раздач.
 */
final class TopicsPeersResponse implements TopicPeersProcessorInterface
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
    ) {}

    /**
     * @return iterable<TopicPeers>
     */
    public function process(array $hashes): iterable
    {
        foreach ($this->peers as $topic) {
            yield $topic;
        }
    }
}
