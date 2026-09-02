<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Data;

final class KeeperTopics
{
    public function __construct(
        public readonly int   $keeperId,
        public readonly int   $topicsCount,
        /** @var KeptTopic[] */
        public readonly array $topics,
    ) {}
}
