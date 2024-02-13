<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\V1;

/** Данные подраздела. */
final class ForumDetails
{
    public function __construct(
        public readonly int    $id,
        public readonly string $name,
        public readonly int    $count,
        public readonly int    $size
    ) {
    }
}
