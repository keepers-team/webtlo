<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo;

/** Простой способ замера времени выполнения. */
final class Timers
{
    /** @var array<string, array<string, mixed>> */
    private static array $markers = [];
    /** @var array<string, string> */
    private static array $stash = [];

    public static function start(string $marker = 'default'): void
    {
        self::$markers[$marker]['start'] = microtime(true);
    }

    public static function stop(string $marker = 'default'): void
    {
        self::$markers[$marker]['stop'] = $stop = microtime(true);

        $start = self::$markers[$marker]['start'] ?? $stop;

        self::$markers[$marker]['exec'] = $stop - $start;
    }

    public static function getExec(string $marker = 'default'): int
    {
        self::stop($marker);

        return (int)floor(self::$markers[$marker]['exec']);
    }

    public static function getExecTime(string $marker = 'default', bool $leadZeros = false): string
    {
        self::stop($marker);

        $time = self::$markers[$marker]['exec'] ?? 0;

        return Helper::convertSeconds((int)$time, $leadZeros);
    }

    public static function printExecTime(string $marker = 'default'): void
    {
        echo sprintf('ExecTime [%s]: %s<br>', $marker, self::getExecTime($marker));
    }

    public static function stash(string $marker = 'default'): void
    {
        self::$stash[$marker] = self::getExecTime($marker);
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
}
