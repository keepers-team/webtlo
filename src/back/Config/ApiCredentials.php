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
        public readonly string $btKey,
        public readonly string $apiKey,
    ) {
    }

    /**
     * Проверим наличие нужных значений в настройках.
     *
     * @param array<string, mixed> $cfg
     * @return ApiCredentials
     * @throws RuntimeException()
     */
    public static function fromLegacy(array $cfg): self
    {
        if (empty($cfg['user_id']) || empty($cfg['bt_key']) || empty($cfg['api_key'])) {
            throw new RuntimeException('Отсутствуют ключи пользователя для доступа к API. Пройдите авторизацию.');
        }

        return new self(
            (int)$cfg['user_id'],
            (string)$cfg['bt_key'],
            (string)$cfg['api_key'],
        );
    }
}
