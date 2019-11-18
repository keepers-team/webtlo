<?php

// часовой пояс по умолчанию
if (!ini_get('date.timezone')) {
    date_default_timezone_set('Europe/Moscow');
}

// текущая дата
class Date
{

    private static $now;

    public static function now()
    {
        if (empty(self::$now)) {
            self::$now = new DateTime('now');
        }
        return self::$now;
    }

}
