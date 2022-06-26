<?php

/**
 * Get normalized path, like realpath() for non-existing path or file
 *
 * @param string $path path to be normalized
 */
function normalizePath(string $path)
{
    return array_reduce(explode(DIRECTORY_SEPARATOR, $path), function ($left, $right) {
        if ($left === null) {
            $left = DIRECTORY_SEPARATOR;
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
function getStorageDir()
{
    $directory = getenv('WEBTLO_DIR');
    if ($directory === false) {
        return normalizePath(dirname(__FILE__) . "/../../data");
    } else {
        return $directory;
    }
}
