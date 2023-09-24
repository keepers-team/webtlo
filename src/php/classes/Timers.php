<?php

/**
 * Простой способ замера времени выполнения.
 */
class Timers
{
    private static array $markers = [];
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

        return floor(self::$markers[$marker]['exec']);
    }

    public static function getExecTime(string $marker = 'default', bool $leadZeros = false): string
    {
        self::stop($marker);

        return convert_seconds(self::$markers[$marker]['exec'] ?? 0, $leadZeros);
    }

    public static function printExecTime(string $marker = 'default'): void
    {
        echo sprintf('ExecTime [%s]: %s<br>', $marker, self::getExecTime($marker));
    }

    public static function stash(string $marker = 'default'): void
    {
        self::$stash[$marker] = self::getExecTime($marker);
    }

    public static function getStash(): array
    {
        $stash = self::$stash;
        self::$stash = [];

        return $stash;
    }
}
