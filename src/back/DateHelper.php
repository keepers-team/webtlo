<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class DateHelper
{
    private const UTC = 'UTC';

    private static ?DateTimeZone $currentTimeZone = null;

    /**
     * Создать объект даты из timestamp или строки. Если не получилось - unix-0.
     */
    public static function makeDateTime(int|string $datetime, bool $utc = false): DateTimeImmutable
    {
        $tz = $utc ? new DateTimeZone(self::UTC) : null;

        try {
            if (is_int($datetime)) {
                return (new DateTimeImmutable(timezone: $tz))->setTimestamp($datetime);
            }

            return new DateTimeImmutable(datetime: $datetime, timezone: $tz);
        } catch (Throwable) {
            // Если не срослось - используем unix ноль.
            return (new DateTimeImmutable())->setTimestamp(0);
        }
    }

    /**
     * Попытка получить объект UTC даты из строки. Если не получилось - null.
     */
    public static function tryUtcFromString(string $datetime): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable(datetime: $datetime, timezone: new DateTimeZone(self::UTC));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Текущая дата по UTC.
     */
    public static function getUtcCurrent(): DateTimeImmutable
    {
        return (new DateTimeImmutable())->setTimezone(new DateTimeZone(self::UTC));
    }

    /**
     * Сменились ли сутки, между двумя датами по UTC.
     */
    public static function isUtcDayChanged(
        DateTimeImmutable $prevDate,
        DateTimeImmutable $newDate = new DateTimeImmutable(),
    ): bool {
        $prevDate = $prevDate->setTimezone(new DateTimeZone(self::UTC));
        $newDate  = $newDate->setTimezone(new DateTimeZone(self::UTC));

        return $newDate > $prevDate && $newDate->format('Y-m-d') !== $prevDate->format('Y-m-d');
    }

    public static function setCurrentTimeZone(DateTimeImmutable $datetime): DateTimeImmutable
    {
        return $datetime->setTimezone(self::getCurrentTimeZone());
    }

    private static function getCurrentTimeZone(): DateTimeZone
    {
        if (self::$currentTimeZone !== null) {
            return self::$currentTimeZone;
        }

        try {
            $tz = new DateTimeZone(date_default_timezone_get());
        } catch (Throwable) {
            $tz = new DateTimeZone(self::UTC);
        }

        return self::$currentTimeZone = $tz;
    }
}
