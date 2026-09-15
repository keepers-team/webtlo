<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use KeepersTeam\Webtlo\DateHelper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 *
 * @covers \KeepersTeam\Webtlo\DateHelper
 */
final class DateHelperTest extends TestCase
{
    protected function setUp(): void
    {
        // Сброс статического кэша таймзоны
        $reflection = new ReflectionClass(DateHelper::class);
        $property   = $reflection->getProperty('currentTimeZone');
        $property->setAccessible(true);
        $property->setValue(null, null);

        // Фиксируем дефолтную таймзону, чтобы тесты были детерминированными
        date_default_timezone_set('Europe/Moscow');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set('UTC');
    }

    public function testMakeDateTimeWithIntAndUtcTrue(): void
    {
        $timestamp = 1700000000; // 2023-11-14 22:13:20 UTC

        $dt = DateHelper::makeDateTime($timestamp, true);

        self::assertSame('UTC', $dt->getTimezone()->getName());
        self::assertSame($timestamp, $dt->getTimestamp());
        self::assertSame('2023-11-14 22:13:20', $dt->format('Y-m-d H:i:s'));
    }

    public function testMakeDateTimeWithIntAndUtcFalse(): void
    {
        $timestamp = 1700000000; // 2023-11-14 22:13:20 UTC

        $dt = DateHelper::makeDateTime($timestamp, false);

        self::assertSame('Europe/Moscow', $dt->getTimezone()->getName());
        self::assertSame($timestamp, $dt->getTimestamp());

        // Europe/Moscow = UTC+3, поэтому 2023-11-15 01:13:20
        self::assertSame('2023-11-15 01:13:20', $dt->format('Y-m-d H:i:s'));
    }

    public function testMakeDateTimeWithStringAndUtcTrue(): void
    {
        $dt = DateHelper::makeDateTime('2023-11-14 22:13:20', true);

        self::assertSame('UTC', $dt->getTimezone()->getName());
        self::assertSame('2023-11-14 22:13:20', $dt->format('Y-m-d H:i:s'));
    }

    public function testMakeDateTimeWithStringAndUtcFalse(): void
    {
        $dt = DateHelper::makeDateTime('2023-11-14 22:13:20', false);

        self::assertSame('Europe/Moscow', $dt->getTimezone()->getName());
        self::assertSame('2023-11-14 22:13:20', $dt->format('Y-m-d H:i:s'));
    }

    public function testMakeDateTimeWithInvalidStringReturnsUnixZeroInUtc(): void
    {
        // В catch-блоке таймзона не передаётся, поэтому используется дефолтная
        $dt = DateHelper::makeDateTime('not-a-date', true);

        self::assertSame('Europe/Moscow', $dt->getTimezone()->getName());
        self::assertSame(0, $dt->getTimestamp());
        self::assertSame('1970-01-01 03:00:00', $dt->format('Y-m-d H:i:s'));
    }

    public function testMakeDateTimeWithInvalidStringReturnsUnixZeroInDefaultTimezone(): void
    {
        $dt = DateHelper::makeDateTime('not-a-date', false);

        self::assertSame('Europe/Moscow', $dt->getTimezone()->getName());
        self::assertSame(0, $dt->getTimestamp());
        self::assertSame('1970-01-01 03:00:00', $dt->format('Y-m-d H:i:s'));
    }

    public function testTryUtcFromStringWithValidStringNoTimezone(): void
    {
        $dt = DateHelper::tryUtcFromString('2023-11-14 22:13:20');

        self::assertInstanceOf(DateTimeImmutable::class, $dt);
        self::assertSame('UTC', $dt->getTimezone()->getName());
        self::assertSame('2023-11-14 22:13:20', $dt->format('Y-m-d H:i:s'));
    }

    public function testTryUtcFromStringWithValidStringWithTimezone(): void
    {
        // Если в строке указана таймзона, PHP игнорирует переданную UTC
        $dt = DateHelper::tryUtcFromString('2023-11-14 22:13:20+03:00');

        self::assertInstanceOf(DateTimeImmutable::class, $dt);
        self::assertSame('+03:00', $dt->getTimezone()->getName());
        self::assertSame('2023-11-14 22:13:20', $dt->format('Y-m-d H:i:s'));
        self::assertSame(
            '2023-11-14 19:13:20',
            $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
        );
    }

    public function testTryUtcFromStringWithInvalidString(): void
    {
        $dt = DateHelper::tryUtcFromString('not-a-date');

        self::assertNull($dt);
    }

    public function testGetUtcCurrent(): void
    {
        $dt = DateHelper::getUtcCurrent();

        self::assertSame('UTC', $dt->getTimezone()->getName());
        self::assertEqualsWithDelta(time(), $dt->getTimestamp(), 5);
    }

    public function testIsUtcDayChangedReturnsTrueWhenDayChanged(): void
    {
        $prev = new DateTimeImmutable('2023-11-14 23:59:59', new DateTimeZone('UTC'));
        $new  = new DateTimeImmutable('2023-11-15 00:00:01', new DateTimeZone('UTC'));

        self::assertTrue(DateHelper::isUtcDayChanged($prev, $new));
    }

    public function testIsUtcDayChangedReturnsFalseWhenSameDay(): void
    {
        $prev = new DateTimeImmutable('2023-11-14 00:00:00', new DateTimeZone('UTC'));
        $new  = new DateTimeImmutable('2023-11-14 23:59:59', new DateTimeZone('UTC'));

        self::assertFalse(DateHelper::isUtcDayChanged($prev, $new));
    }

    public function testIsUtcDayChangedReturnsFalseWhenNewDateIsEarlier(): void
    {
        $prev = new DateTimeImmutable('2023-11-15 00:00:00', new DateTimeZone('UTC'));
        $new  = new DateTimeImmutable('2023-11-14 23:59:59', new DateTimeZone('UTC'));

        self::assertFalse(DateHelper::isUtcDayChanged($prev, $new));
    }

    public function testIsUtcDayChangedWithDifferentTimezones(): void
    {
        // 2023-11-14 23:00 UTC == 2023-11-15 02:00 Europe/Moscow
        $prev = new DateTimeImmutable('2023-11-14 23:00:00', new DateTimeZone('UTC'));
        $new  = new DateTimeImmutable('2023-11-15 02:00:00', new DateTimeZone('Europe/Moscow'));

        // Оба соответствуют 2023-11-14 по UTC, день не сменился
        self::assertFalse(DateHelper::isUtcDayChanged($prev, $new));

        // 2023-11-15 03:00 Europe/Moscow == 2023-11-15 00:00 UTC
        $new2 = new DateTimeImmutable('2023-11-15 03:00:00', new DateTimeZone('Europe/Moscow'));

        self::assertTrue(DateHelper::isUtcDayChanged($prev, $new2));
    }

    public function testSetCurrentTimeZoneUsesDefaultTimezone(): void
    {
        date_default_timezone_set('America/New_York');

        $dt = new DateTimeImmutable('2023-11-14 22:13:20', new DateTimeZone('UTC'));

        $result = DateHelper::setCurrentTimeZone($dt);

        self::assertSame('America/New_York', $result->getTimezone()->getName());
        self::assertSame('2023-11-14 17:13:20', $result->format('Y-m-d H:i:s'));
    }

    public function testSetCurrentTimeZoneCachesTimezone(): void
    {
        date_default_timezone_set('Europe/Moscow');

        $dt = new DateTimeImmutable('2023-11-14 22:13:20', new DateTimeZone('UTC'));

        $result1 = DateHelper::setCurrentTimeZone($dt);
        self::assertSame('Europe/Moscow', $result1->getTimezone()->getName());

        // Меняем дефолтную таймзону — кэш не должен обновиться
        date_default_timezone_set('America/New_York');

        $result2 = DateHelper::setCurrentTimeZone($dt);
        self::assertSame('Europe/Moscow', $result2->getTimezone()->getName());
    }
}
