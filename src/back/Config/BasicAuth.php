<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

final class BasicAuth
{
    public function __construct(
        public readonly string $username,
        public readonly string $password,
    ) {}
}
