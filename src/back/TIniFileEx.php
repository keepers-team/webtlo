<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo;

final class TIniFileEx
{
    private const DEFAULT = 'config.ini';

    /** @var array<string, mixed> */
    private static array $readConfig;
    /** @var array<string, mixed> */
    private static array $writeConfig;

    private static string $filename;

    public function __construct(string $filename = '')
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
        self::$readConfig = is_readable(self::$filename) ? parse_ini_file(self::$filename, true) : [];
    }

    private static function setFile(string $filename): void
    {
        self::$filename = Helper::getStorageDir() . DIRECTORY_SEPARATOR . $filename;
    }

    public static function getFile(): string
    {
        return self::$filename;
    }

    public static function read(int|string $section, int|string $key, mixed $def = ''): mixed
    {
        if (!isset(self::$readConfig)) {
            self::loadFromFile();
        }

        return self::$readConfig[$section][$key] ?? $def;
    }

    public static function write(int|string $section, int|string $key, mixed $value): void
    {
        if (is_bool($value)) {
            $value = $value ? 1 : 0;
        }
        self::$writeConfig[$section][$key] = $value;
    }

    public static function writeFile(): bool
    {
        if (empty(self::$writeConfig)) {
            return false;
        }
        if (!isset(self::$readConfig)) {
            self::loadFromFile();
        }

        $_BR_ = chr(13) . chr(10);

        self::$readConfig = array_replace_recursive(self::$readConfig, self::$writeConfig);

        $result = '';
        foreach (self::$readConfig as $secName => $section) {
            $result .= '[' . $secName . ']' . $_BR_;
            foreach ($section as $key => $value) {
                $result .= sprintf('%s="%s"%s', $key, str_replace('\\', '\\\\', (string)$value), $_BR_);
            }
            $result .= $_BR_;
        }

        // Write config file atomically
        $wRes = file_put_contents(self::$filename . ".tmp", $result, LOCK_EX);
        if ($wRes === false) {
            return false;
        }
        $r = rename(self::$filename . ".tmp", self::$filename);
        if ($r === false) {
            return false;
        }

        return (bool)$wRes;
    }

    public static function cloneFile(string $filename): void
    {
        self::$writeConfig = self::$readConfig;
        self::setFile($filename);
    }
}
