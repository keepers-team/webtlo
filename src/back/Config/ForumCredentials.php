<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

use RuntimeException;

/**
 * Данные пользователя для доступа к форуму.
 */
final class ForumCredentials
{
    public function __construct(
        public readonly BasicAuth $auth,
        public readonly ?string   $session = null,
    ) {}

    /**
     * Проверим наличие нужных значений для авторизации на форуме.
     *
     * @param array<string, mixed> $cfg
     * @return ForumCredentials
     */
    public static function fromLegacy(array $cfg): self
    {
        if (empty($cfg['tracker_login'])) {
            throw new RuntimeException("Не указано имя пользователя для доступа к форуму. Проверьте настройки.");
        }
        if (empty($cfg['tracker_paswd'])) {
            throw new RuntimeException("Не указан пароль пользователя для доступа к форуму. Проверьте настройки.");
        }

        return new self(
            new BasicAuth(
                (string) $cfg['tracker_login'],
                (string) $cfg['tracker_paswd']
            ),
            $cfg['user_session'] ?: null,
        );
    }

    public static function fromFrontProperties(string $login, string $password): self
    {
        return new self(new BasicAuth($login, $password));
    }
}
