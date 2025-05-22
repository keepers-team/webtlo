<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

final class ForumConnect
{
    public function __construct(
        public readonly string  $baseUrl,
        public readonly bool    $ssl,
        public readonly bool    $useProxy,
        public readonly Timeout $timeout,
    ) {}
}
