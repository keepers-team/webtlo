<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo;

use Exception;

final class Helper
{
    public static function natsortField(array $input, string $field, int $direct = 1): array
    {
        uasort($input, function($a, $b) use ($field, $direct) {
            if (
                is_numeric($a[$field])
                && is_numeric($b[$field])
            ) {
                return ($a[$field] != $b[$field] ? $a[$field] < $b[$field] ? -1 : 1 : 0) * $direct;
            }
            $a[$field] = mb_ereg_replace('ё', 'е', mb_strtolower($a[$field], 'UTF-8'));
            $b[$field] = mb_ereg_replace('ё', 'е', mb_strtolower($b[$field], 'UTF-8'));

            return (strnatcasecmp($a[$field], $b[$field])) * $direct;
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
        $pad = fn(int $val): string => !$leadZeros ? (string)$val : str_pad((string)$val, 2, '0', STR_PAD_LEFT);

        $hh = (int)floor($seconds / 3600);
        $mm = (int)floor($seconds / 60 % 60);
        $ss = $seconds % 60;

        if ($hh > 0) {
            return sprintf('%sh %sm %ss', $pad($hh), $pad($mm), $pad($ss));
        }
        if ($mm > 0) {
            return sprintf('%sm %ss', $pad($mm), $pad($ss));
        }

        return sprintf('%ss', $pad($ss));
    }

    /** Создать каталог по заданному пути. */
    public static function makeDirRecursive(string $path): bool
    {
        $return = false;
        if (PHP_OS === 'WINNT') {
            $winPath = mb_convert_encoding($path, 'Windows-1251', 'UTF-8');
            if (is_writable($winPath) && is_dir($winPath)) {
                return true;
            }
        }
        if (is_writable($path) && is_dir($path)) {
            return true;
        }

        $prevPath = dirname($path);
        if ($path != $prevPath) {
            $return = self::makeDirRecursive($prevPath);
        }
        if (PHP_OS === 'WINNT') {
            $winPrevPath = mb_convert_encoding($prevPath, 'Windows-1251', 'UTF-8');

            return $return && is_writable($winPrevPath) && !file_exists($winPath) && mkdir($winPath);
        }

        return $return && is_writable($prevPath) && !file_exists($path) && mkdir($path);
    }

    /**
     * Проверить наличие каталога и попробовать его создать.
     *
     * @throws Exception
     */
    public static function checkDirRecursive(string $path): void
    {
        if (!file_exists($path)) {
            if (!self::makeDirRecursive($path)) {
                throw new Exception('Не удалось создать каталог ' . $path);
            }
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
        foreach (scandir($path) as $next_path) {
            if ('.' === $next_path || '..' === $next_path) {
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
                __DIR__ . DIRECTORY_SEPARATOR . str_repeat(".." . DIRECTORY_SEPARATOR, 1) . 'data'
            );
        } else {
            return $directory;
        }
    }

    /**
     * @return string The log directory for the application
     */
    public static function getLogDir(): string
    {
        return self::getStorageDir() . DIRECTORY_SEPARATOR . "logs";
    }

    /**
     * Get normalized path, like realpath() for non-existing path or file
     */
    public static function normalizePath(string $path): string
    {
        return array_reduce(explode(DIRECTORY_SEPARATOR, $path), function($left, $right) {
            if ($left === null) {
                return $right;
            }
            if ($right === "" || $right === ".") {
                return $left;
            }
            if ($right === "..") {
                return dirname($left);
            }
            $pattern = sprintf("/\%s+/", DIRECTORY_SEPARATOR);

            return preg_replace($pattern, DIRECTORY_SEPARATOR, $left . DIRECTORY_SEPARATOR . $right);
        });
    }

    /** Найти используемый домен трекера в настройках. */
    public static function getForumDomain(array $cfg): ?string
    {
        if (!empty($cfg['forum_url'] && $cfg['forum_url'] !== 'custom')) {
            return $cfg['forum_url'];
        } elseif (!empty($cfg['forum_url_custom'])) {
            return $cfg['forum_url_custom'];
        }

        return null;
    }
}
