<?php

namespace KeepersTeam\Webtlo\Config;

final class ApiCredentials
{
    public function __construct(
        public readonly string $userId,
        public readonly string $btKey,
        public readonly string $apiKey,
    ) {
    }
}
