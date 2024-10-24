<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo;

final class App
{
    private static bool $initialized = false;

    public static function init(): void
    {
        if (!self::$initialized) {
            // Проставляем часовой пояс.
            self::setDefaultTimeZone();

            self::$initialized = true;
        }
    }

    /** Часовой пояс по-умолчанию */
    private static function setDefaultTimeZone(): void
    {
        if (!ini_get('date.timezone')) {
            date_default_timezone_set(getenv('TZ') ?: 'Europe/Moscow');
        }
    }
}
