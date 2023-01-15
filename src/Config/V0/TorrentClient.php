<?php

namespace KeepersTeam\Webtlo\Config\V0;

use KeepersTeam\Webtlo\Clients\SupportedClientType;

final class TorrentClient
{
    public function __construct(
        public readonly string $id,
        public readonly SupportedClientType $type,
        public readonly TorrentClientConfig $config,
    ) {
    }
}
