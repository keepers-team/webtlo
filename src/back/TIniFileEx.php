<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo;

use Log;

class TIniFileEx
{
    private const DEFAULT = 'config.ini';

    protected static array $rcfg;
    protected static array $wcfg;

    public static string $filename;

    public function __construct($filename = '')
    {
        if (!empty($filename)) {
            self::setFile($filename);
        }
        $this->loadFromFile();
    }

    private static function loadFromFile(): void
    {
        if (empty(self::$filename)) {
            self::setFile(self::DEFAULT);
        }
        self::$rcfg = is_readable(self::$filename) ? parse_ini_file(self::$filename, true) : [];
    }

    private static function setFile($filename): void
    {
        self::$filename = Helper::getStorageDir() . DIRECTORY_SEPARATOR . $filename;
    }

    public static function getFile(): string
    {
        return self::$filename;
    }

    public static function read(int|string $section, int|string $key, mixed $def = ""): mixed
    {
        if (!isset(self::$rcfg)) {
            self::loadFromFile();
        }
        return self::$rcfg[$section][$key] ?? $def;
    }

    public static function write(int|string $section, int|string $key, mixed $value): void
    {
        if (is_bool($value)) {
            $value = $value ? 1 : 0;
        }
        self::$wcfg[$section][$key] = $value;
    }

    public static function writeFile(): bool|int
    {
        if (empty(self::$wcfg)) {
            return false;
        }
        if (!isset(self::$rcfg)) {
            self::loadFromFile();
        }

        $_BR_ = chr(13) . chr(10);

        self::$rcfg = array_replace_recursive(self::$rcfg, self::$wcfg);
        $result = "";
        foreach (self::$rcfg as $secName => $section) {
            $result .= '[' . $secName . ']' . $_BR_;
            foreach ($section as $key => $value) {
                $result .= sprintf('%s="%s"%s', $key, str_replace('\\', '\\\\', (string)$value), $_BR_);
            }
            $result .= $_BR_;
        }

        // Write config file atomically
        $wRes = file_put_contents(self::$filename .".tmp", $result, LOCK_EX);
        if ($wRes === false) {
            return false;
        }
        $r = rename(self::$filename . ".tmp", self::$filename);
        if ($r === false) {
            return false;
        }
        return $wRes;
    }

    public static function updateFile(): void
    {
        $result = self::writeFile();
        Log::append($result
            ? 'Настройки успешно сохранены в файл.'
            : 'Не удалось записать настройки в файл.');
    }

    public static function copyFile(string $filename): void
    {
        self::$wcfg = self::$rcfg;
        self::setFile($filename);
    }
}
