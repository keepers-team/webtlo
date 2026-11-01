<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Data;

final class Topic
{
    /**
     * @param positive-int     $id
     * @param non-empty-string $hash
     */
    public function __construct(
        public readonly int    $id,
        public readonly string $hash,
    ) {}
}
