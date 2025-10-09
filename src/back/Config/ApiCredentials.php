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
            throw new RuntimeException('Отсутствует ид пользователя. Пройдите авторизацию.');
        }
        if ($this->btKey === '' || $this->apiKey === '') {
            throw new RuntimeException('Отсутствуют ключи пользователя для доступа к API. Пройдите авторизацию.');
        }
    }

    /**
     * @return array{api_key: string}
     */
    public function getApiKey(): array
    {
        $this->validate();

        return ['api_key' => $this->apiKey];
    }
}
