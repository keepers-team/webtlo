<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Contract;

use KeepersTeam\Webtlo\External\Data\TopicPeers;

/**
 * Интерфейс для ленивого перебора раздач с данными о пирах.
 *
 * @see TopicPeers
 */
interface TopicPeersProcessorInterface
{
    /**
     * @param string[] $hashes
     *
     * @return iterable<TopicPeers>
     */
    public function process(array $hashes): iterable;
}
