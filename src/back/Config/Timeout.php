<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

final class Timeout
{
    public function __construct(
        public readonly int $request = Defaults::timeout,
        public readonly int $connection = Defaults::timeout
    ) {
    }
}
