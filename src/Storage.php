<?php

namespace KeepersTeam\Webtlo;

final class Storage
{
    /**
     * Get normalized path, like realpath() for non-existing path or file
     *
     * @param string $path path to be normalized
     */
    private static function normalizePath(string $path): string
    {
        return array_reduce(explode(DIRECTORY_SEPARATOR, $path), function ($left, $right) {
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

    /**
     * @return string Storage directory for application
     */
    public static function getStorageDir(): string
    {
        $directory = getenv('WEBTLO_DIR');
        if ($directory === false) {
            return self::normalizePath(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . 'data');
        } else {
            return $directory;
        }
    }
}
