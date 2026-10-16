<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

/**
 * Простой способ замера времени выполнения.
 */
final class Timers
{
    /** @var array<string, array<string, int|float>> */
    private static array $markers = [];

    /** @var array<string, string> */
    private static array $stash = [];

    private static ?ClockInterface $clock = null;

    /**
     * Подмена часов.
     */
    public static function setClock(?ClockInterface $clock): void
    {
        self::$clock = $clock;
    }

    public static function reset(): void
    {
        self::$markers = [];
        self::$stash   = [];
    }

    public static function start(string $marker = 'default'): void
    {
        self::$markers[$marker] = ['start' => self::now()];
    }

    public static function stop(string $marker = 'default'): void
    {
        // Уже остановлен — не перезаписываем.
        if (isset(self::$markers[$marker]['exec'])) {
            return;
        }

        $stop  = self::now();
        $start = self::$markers[$marker]['start'] ?? $stop;

        self::$markers[$marker]['stop'] = $stop;
        self::$markers[$marker]['exec'] = $stop - $start;
    }

    public static function getExecTime(string $marker = 'default', bool $leadZeros = false): string
    {
        self::stop(marker: $marker);

        $time = self::$markers[$marker]['exec'] ?? 0;

        return Helper::convertSeconds(seconds: (int) $time, leadZeros: $leadZeros);
    }

    public static function stash(string $marker = 'default'): void
    {
        self::$stash[$marker] = self::getExecTime(marker: $marker);
    }

    /**
     * @return array<string, string>
     */
    public static function getStash(): array
    {
        $stash = self::$stash;

        self::$stash = [];

        return $stash;
    }

    private static function now(): float
    {
        $clock = self::$clock ??= new Clock();

        return (float) $clock->now()->format('U.u');
    }
}
