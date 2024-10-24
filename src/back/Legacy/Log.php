<?php

namespace KeepersTeam\Webtlo\Legacy;

use KeepersTeam\Webtlo\Helper;

final class Log
{
    /** @var string[] */
    private static array $log = [];

    public static function append(string $message = ''): void
    {
        if (!empty($message)) {
            self::$log[] = date('d.m.Y H:i:s') . ' ' . $message;
        }
    }

    /**
     * @param string[] $rows
     */
    public static function formatRows(array $rows, string $break = '<br />', bool $replace = false): string
    {
        $splitWord = '-- DONE --';

        $output   = [];
        $blockNum = 0;
        foreach ($rows as $row) {
            $isSplit = str_contains($row, $splitWord);

            $output[$blockNum][] = $isSplit ? $splitWord : $row;
            if ($isSplit) {
                $output[$blockNum][] = '';
                ++$blockNum;
            }
        }

        // Переворачиваем порядок процессов. Последний - вверху.
        $output = array_merge(...array_reverse($output));

        // Заменяем спецсимволы для вывода в UI.
        if ($replace) {
            $output = array_map(fn($el) => htmlspecialchars($el), $output);
        }

        return implode($break, $output) . $break;
    }

    public static function get(string $break = '<br />'): string
    {
        if (!empty(self::$log)) {
            return self::formatRows(rows: self::$log, break: $break);
        }

        return '';
    }

    public static function write(string $logFile): void
    {
        $dir = Helper::getLogDir();

        $result = is_writable($dir) || mkdir($dir);
        if (!$result) {
            echo 'Нет или недостаточно прав для доступа к каталогу logs';
        }

        $logFile = "$dir/$logFile";
        self::move($logFile);
        if ($logFile = fopen($logFile, 'a')) {
            fwrite($logFile, self::get("\n"));
            fwrite($logFile, " -- DONE --\n");
            fclose($logFile);
        } else {
            echo 'Не удалось создать файл лога.';
        }
    }

    private static function move(string $logFile): void
    {
        // переименовываем файл лога, если он больше 5 Мб
        if (file_exists($logFile) && filesize($logFile) >= 5242880) {
            if (!rename($logFile, (string) preg_replace('|.log$|', '.1.log', $logFile))) {
                echo 'Не удалось переименовать файл лога.';
            }
        }
    }
}
