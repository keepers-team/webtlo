<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

/**
 * Данные текущего пользователя.
 */
final class UserInfo
{
    public function __construct(
        public readonly int    $userId,
        public readonly string $userName,
    ) {}
}
