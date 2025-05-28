<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use KeepersTeam\Webtlo\External\Data\TopicPeers;

interface TopicPeersProcessorInterface
{
    /**
     * @param string[] $hashes
     *
     * @return iterable<TopicPeers>
     */
    public function process(array $hashes): iterable;
}
