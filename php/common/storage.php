<?php

/**
 * Get normalized path, like realpath() for non-existing path or file
 *
 * @param string $path path to be normalized
 */
function normalizePath($path)
{
	if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
		$path = str_replace('/', '\\', $path);// Replace backslashes with forwardslashes
	}
    return array_reduce(explode(DIRECTORY_SEPARATOR, $path), function ($left, $right) {
		if ($left === null && !strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && strpos($right, "://")) {
            $left = DIRECTORY_SEPARATOR;
        }
        if ($right === "" || $right === ".") {
            return $left;
        }
        if ($right === "..") {
            return dirname($left);
        }
        $pattern = sprintf("/\%s+/", DIRECTORY_SEPARATOR);
		return preg_replace($pattern, DIRECTORY_SEPARATOR, $left . (!is_null($left) ? DIRECTORY_SEPARATOR : null) . $right);
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
