<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

use RuntimeException;

/**
 * Хранительские ключи пользователя для авторизации в API.
 */
final class ApiCredentials
{
    public function __construct(
        public readonly int    $userId,
        public readonly string $btKey = '',
        public readonly string $apiKey = '',
    ) {}

    public function validate(): void
    {
        if ($this->userId <= 0) {
            throw new RuntimeException('Отсутствует ид пользователя. Укажите его в настройках.');
        }
        if ($this->btKey === '' || $this->apiKey === '') {
            throw new RuntimeException('Отсутствуют ключи пользователя для доступа к API. Укажите их в настройках.');
        }
    }
}
