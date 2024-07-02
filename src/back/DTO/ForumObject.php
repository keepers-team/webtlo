<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\DTO;

final class ForumObject
{
    public function __construct(
        public int    $id,
        public string $name,
        public int    $quantity,
        public int    $size,
    ) {
    }
}
