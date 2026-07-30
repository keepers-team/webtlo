<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class DateHelper
{
    public const UTC = 'UTC';

    /**
     * Попытка получить объект даты из строки. Если не получилось - null.
     */
    public static function parseFromString(string $datetime, ?string $timezone = null): ?DateTimeImmutable
    {
        if ($datetime === '') {
            return null;
        }

        try {
            $timezone = self::checkTimeZone(timezone: $timezone);

            return new DateTimeImmutable($datetime, $timezone);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Создать объект даты из строки. Если не получилось - unix-0.
     */
    public static function makeFromString(string $datetime, ?string $timezone = null): DateTimeImmutable
    {
        $dt = self::parseFromString(datetime: $datetime, timezone: $timezone);

        // Если не срослось - используем unix ноль.
        return $dt ?? self::makeFromTimestamp(timestamp: 0, timezone: $timezone);
    }

    /**
     * Создать объект даты из timestamp.
     */
    public static function makeFromTimestamp(int $timestamp, ?string $timezone = null): DateTimeImmutable
    {
        $dt = (new DateTimeImmutable())->setTimestamp($timestamp);

        if ($timezone = self::checkTimeZone(timezone: $timezone)) {
            $dt = $dt->setTimezone($timezone);
        }

        return $dt;
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

    private static function checkTimeZone(?string $timezone = null): ?DateTimeZone
    {
        if ($timezone === self::UTC) {
            return new DateTimeZone(self::UTC);
        }

        return null;
    }
}
