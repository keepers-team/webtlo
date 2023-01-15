<?php

namespace KeepersTeam\Webtlo\Config\V0;

use KeepersTeam\Webtlo\Config\Credentials;
use KeepersTeam\Webtlo\Config\Timeout;

final class TorrentClientConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly bool $secure = false,
        public readonly ?Credentials $credentials = null,
        public readonly Timeout $timeout = new Timeout(),
        public readonly array $extra = [],
    ) {
    }
}
