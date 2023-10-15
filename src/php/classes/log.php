<?php

use KeepersTeam\Webtlo\Helper;

class Log
{
    private static array $log = [];

    public static function append(string $message = ''): void
    {
        if (!empty($message)) {
            self::$log[] = date('d.m.Y H:i:s') . ' ' . $message;
        }
    }

    public static function get($break = '<br />'): string
    {
        if (!empty(self::$log)) {
            return implode($break, self::$log) . $break;
        }

        return '';
    }

    public static function write($logFile): void
    {
        $dir = Helper::getLogDir();

        $result = is_writable($dir) || mkdir($dir);
        if (!$result) {
            echo "Нет или недостаточно прав для доступа к каталогу logs";
        }

        $logFile = "$dir/$logFile";
        self::move($logFile);
        if ($logFile = fopen($logFile, "a")) {
            fwrite($logFile, self::get("\n"));
            fwrite($logFile, " -- DONE --\n");
            fclose($logFile);
        } else {
            echo "Не удалось создать файл лога.";
        }
    }

    private static function move(string $logFile): void
    {
        // переименовываем файл лога, если он больше 5 Мб
        if (file_exists($logFile) && filesize($logFile) >= 5242880) {
            if (!rename($logFile, preg_replace('|.log$|', '.1.log', $logFile))) {
                echo "Не удалось переименовать файл лога.";
            }
        }
    }
}
