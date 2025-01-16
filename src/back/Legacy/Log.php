<?php

namespace KeepersTeam\Webtlo\Legacy;

final class Log
{
    /** @var string[] */
    private static array $log = [];

    public static function append(string $message = ''): void
    {
        if ('' !== $message) {
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
        if (count(self::$log)) {
            return self::formatRows(rows: self::$log, break: $break);
        }

        return '';
    }
}
