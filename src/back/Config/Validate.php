<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

use Exception;

final class Validate
{
    /**
     * Проверим наличие нужных данных о пользователе.
     * @throws Exception
     */
    public static function checkUser(array $cfg): Credentials
    {
        if (empty($cfg['tracker_login'])) {
            throw new Exception("Error: Не указано имя пользователя для доступа к форуму. Проверьте настройки.");
        }

        if (empty($cfg['tracker_paswd'])) {
            throw new Exception("Error: Не указан пароль пользователя для доступа к форуму. Проверьте настройки.");
        }

        if (empty($cfg['user_id'])) {
            throw new Exception("Error: Не указаны ключи пользователя для доступа к форуму. Пройдите авторизацию.");
        }
        if (empty($cfg['bt_key'])) {
            throw new Exception("Error: Не указаны ключи пользователя для доступа к форуму. Пройдите авторизацию.");
        }
        if (empty($cfg['api_key'])) {
            throw new Exception("Error: Не указаны ключи пользователя для доступа к форуму. Пройдите авторизацию.");
        }

        return new Credentials(
            $cfg['tracker_login'],
            $cfg['tracker_paswd'],
            (int)$cfg['user_id'],
            $cfg['bt_key'],
            $cfg['api_key'],
            $cfg['user_session'] ?: null
        );
    }
}