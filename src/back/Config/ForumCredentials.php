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

    public function validate(): void
    {
        if ($this->auth->username === '') {
            throw new RuntimeException('Не указано имя пользователя для доступа к форуму. Проверьте настройки.');
        }
        if ($this->auth->password === '') {
            throw new RuntimeException('Не указан пароль пользователя для доступа к форуму. Проверьте настройки.');
        }
    }

    public static function fromFrontProperties(string $login, string $password): self
    {
        return new self(new BasicAuth($login, $password));
    }
}
