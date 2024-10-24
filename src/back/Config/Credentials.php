<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

use Exception;

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
    ) {}

    /**
     * Проверим наличие нужных данных о пользователе.
     *
     * @param array<string, mixed> $cfg
     * @throws Exception
     */
    public static function fromLegacy(array $cfg): self
    {
        return Validate::checkUser($cfg);
    }
}
