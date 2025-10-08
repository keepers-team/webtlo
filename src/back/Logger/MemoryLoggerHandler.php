<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Logger;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Хранение журнала в памяти приложения.
 */
final class MemoryLoggerHandler extends AbstractProcessingHandler implements HandlerInterface
{
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    /** @var string[] */
    private static array $records = [];

    public function __construct(int|string|Level $level = Level::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $message = trim((string) $record->formatted);
        if ($message !== '') {
            self::$records[] = date(self::DATE_FORMAT) . ' ' . $message;
        }
    }

    public static function getRecords(string $break = '<br />'): string
    {
        if (count(self::$records)) {
            $formatted = self::formatRows(rows: self::$records, break: $break);

            self::$records = [];

            return $formatted;
        }

        return '';
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

        // Оставляем последние 25 блоков записей.
        $output = array_slice($output, -25);

        // Переворачиваем порядок процессов. Последний - вверху.
        $output = array_merge(...array_reverse($output));

        // Заменяем спецсимволы для вывода в UI.
        if ($replace) {
            $output = array_map(fn($el) => htmlspecialchars($el), $output);
        }

        return implode($break, $output) . $break;
    }
}
