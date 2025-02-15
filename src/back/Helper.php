<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo;

use DateTimeImmutable;
use RuntimeException;

final class Helper
{
    /**
     * Сортировка массива по заданному ключу, с учётом кириллицы.
     *
     * @param array<int|string, mixed> $input
     *
     * @return array<int|string, mixed>
     */
    public static function natsortField(array $input, string $field, int $direct = 1): array
    {
        uasort($input, function($a, $b) use ($field, $direct) {
            $a = $a[$field] ?? 0;
            $b = $b[$field] ?? 0;

            if (is_numeric($a) && is_numeric($b)) {
                return ($a <=> $b) * $direct;
            }

            $a = (string) mb_ereg_replace('ё', 'е', mb_strtolower((string) $a, 'UTF-8'));
            $b = (string) mb_ereg_replace('ё', 'е', mb_strtolower((string) $b, 'UTF-8'));

            return strnatcasecmp($a, $b) * $direct;
        });

        return $input;
    }

    /** Конвертация размера в строку. */
    public static function convertBytes(int $size, int $maxPow = 3): string
    {
        $sizeName = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];

        $base = 1024;
        if ($size <= 0) {
            $bytes = $pow = 0;
        } else {
            $pow   = $size >= pow($base, $maxPow) ? $maxPow : floor(log($size, $base));
            $bytes = round($size / pow($base, $pow), 2);
        }

        return sprintf('%s %s', $bytes, $sizeName[$pow]);
    }

    /** Конвертация секунд в строку. */
    public static function convertSeconds(int $seconds, bool $leadZeros = false): string
    {
        $pad = fn(int $val): string => !$leadZeros ? (string) $val : str_pad((string) $val, 2, '0', STR_PAD_LEFT);

        if ($seconds > 0) {
            $minutes = intdiv($seconds, 60);

            $ss = $seconds % 60;
            $mm = $minutes % 60;
            $hh = intdiv($minutes, 60);

            if ($hh > 0) {
                return sprintf('%sh %sm %ss', $pad($hh), $pad($mm), $pad($ss));
            }
            if ($mm > 0) {
                return sprintf('%sm %ss', $pad($mm), $pad($ss));
            }
        }

        return sprintf('%ss', $pad($ss ?? $seconds));
    }

    /** Создать каталог по заданному пути. */
    public static function makeDirRecursive(string $path): bool
    {
        // Не уверен, что эта конвертация нужна, но пусть пока будет.
        if (PHP_OS === 'WINNT') {
            $path = mb_convert_encoding($path, 'Windows-1251', 'UTF-8');
        }

        if (is_dir($path) && is_writable($path)) {
            return true;
        }

        return !file_exists($path) && mkdir($path, 0o777, true);
    }

    /**
     * Проверить наличие каталога и попробовать его создать.
     */
    public static function checkDirRecursive(string $path): void
    {
        if (!self::makeDirRecursive($path)) {
            throw new RuntimeException("Не удалось создать каталог '$path'");
        }
    }

    /** Рекурсивно удалить каталог. */
    public static function removeDirRecursive(string $path): bool
    {
        $return = true;
        if (!file_exists($path)) {
            return true;
        }
        if (!is_dir($path)) {
            return unlink($path);
        }
        foreach ((array) scandir($path) as $next_path) {
            if ($next_path === '.' || $next_path === '..') {
                continue;
            }
            if (is_dir("$path/$next_path")) {
                if (!is_writable("$path/$next_path")) {
                    return false;
                }
                $return = self::removeDirRecursive("$path/$next_path");
            } else {
                unlink("$path/$next_path");
            }
        }

        return $return && is_writable($path) && rmdir($path);
    }

    /**
     * @return string Storage directory for application
     */
    public static function getStorageDir(): string
    {
        $directory = getenv('WEBTLO_DIR');
        if ($directory === false) {
            // Default path is /webtlo/data
            return self::normalizePath(
                __DIR__ . DIRECTORY_SEPARATOR . str_repeat('..' . DIRECTORY_SEPARATOR, 1) . 'data'
            );
        }

        return $directory;
    }

    /**
     * @return string The log directory for the application
     */
    public static function getLogDir(): string
    {
        return self::getStorageDir() . DIRECTORY_SEPARATOR . 'logs';
    }

    /** Получить путь к каталогу/файлу миграций. */
    public static function getMigrationPath(?string $file = null): string
    {
        // webtlo/sql
        $path = __DIR__ . DIRECTORY_SEPARATOR . str_repeat('..' . DIRECTORY_SEPARATOR, 1) . 'sql';

        if ($file !== null) {
            $path .= DIRECTORY_SEPARATOR . $file;
        }

        return self::normalizePath($path);
    }

    /**
     * Get normalized path, like realpath() for non-existing path or file.
     */
    public static function normalizePath(string $path): string
    {
        return array_reduce(explode(DIRECTORY_SEPARATOR, $path), function($left, $right) {
            if ($left === null) {
                return $right;
            }
            if ($right === '' || $right === '.') {
                return $left;
            }
            if ($right === '..') {
                return dirname($left);
            }
            $pattern = sprintf('/\%s+/', DIRECTORY_SEPARATOR);

            return preg_replace($pattern, DIRECTORY_SEPARATOR, $left . DIRECTORY_SEPARATOR . $right);
        });
    }

    /**
     * Разбиение строки по символу с приведением значений к int.
     *
     * @param non-empty-string $separator
     *
     * @return int[]|array{}
     */
    public static function explodeInt(string $string, string $separator = ','): array
    {
        $values = explode($separator, trim($string));

        return array_map('intval', array_filter($values));
    }

    /**
     * @param array<int|string, mixed> $array
     *
     * @return array<int, mixed>
     */
    public static function convertKeysToInt(array $array): array
    {
        $keys = array_map('intval', array_keys($array));

        return array_combine($keys, $array);
    }

    /**
     * @param array<int|string, mixed> $array
     *
     * @return array<string, mixed>
     */
    public static function convertKeysToString(array $array): array
    {
        $keys = array_map('strval', array_keys($array));

        return array_combine($keys, $array);
    }

    public static function makeDateTime(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable())->setTimestamp($timestamp);
    }

    /**
     * Проверить включена ли опция автоматического запуска действия.
     *
     * @param array<string, mixed> $config
     */
    public static function isScheduleActionEnabled(array $config, string $action): bool
    {
        return (bool) ($config['automation'][$action] ?? 0);
    }

    /**
     * Проверить включена ли дополнительная опция обновления раздач.
     *
     * @param array<string, mixed> $config
     */
    public static function isUpdatePropertyEnabled(array $config, string $property): bool
    {
        return (bool) ($config['update'][$property] ?? 0);
    }
}
