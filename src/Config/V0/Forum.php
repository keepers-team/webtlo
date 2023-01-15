<?php

namespace KeepersTeam\Webtlo\Config\V0;

use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\Config\Defaults;
use KeepersTeam\Webtlo\Config\Timeout;

final class Forum
{
    public function __construct(
        public readonly string $login,
        public readonly string $password,
        public readonly ApiCredentials $apiCredentials,
        public readonly string $forumUrl = Defaults::forumUrl,
        public readonly string $apiUrl = Defaults::apiUrl,
        public readonly Timeout $apiTimeout = new Timeout(),
        public readonly Timeout $forumTimeout = new Timeout(),
    ) {
    }
}
