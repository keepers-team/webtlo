<?php

namespace KeepersTeam\Webtlo\External\Api\V1;

final class ForumData
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $count,
        public readonly int $size
    ) {
    }
}
