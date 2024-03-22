<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\V1;

final class KeepersResponse
{
    public function __construct(
        public readonly int   $forumId,
        /** @var KeeperTopics[] */
        public readonly array $keepers,
    ) {
    }
}
