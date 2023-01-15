<?php

namespace KeepersTeam\Webtlo\Config;

final class Credentials
{
    public function __construct(
        public readonly string $username,
        public readonly string $password,
    ) {
    }
}
