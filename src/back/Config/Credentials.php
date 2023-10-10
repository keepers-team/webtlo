<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

/** Данные пользователя для доступа к форуму. */
final class Credentials
{
    public function __construct(
        public readonly string  $userName,
        public readonly string  $password,
        public readonly int     $userId,
        public readonly string  $btKey,
        public readonly string  $apiKey,
        public readonly ?string $session = null,
    ) {
    }
}