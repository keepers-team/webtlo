<?php

namespace KeepersTeam\Webtlo\Config\V0;

final class Curators
{
    public function __construct(
        public readonly string $dirTorrents = 'temp',
        public readonly ?string $userPasskey = null,
        public readonly bool $torForUser = false,
    ) {
    }
}
