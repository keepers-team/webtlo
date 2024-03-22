<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\V1;

final class KeeperTopics
{
    public function __construct(
        public readonly int   $keeperId,
        /** @var KeptTopic[] */
        public readonly array $topics
    ) {
    }
}
