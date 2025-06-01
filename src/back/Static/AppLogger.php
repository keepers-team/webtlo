<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Static;

use Cesargb\Log\Rotation;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Legacy\LogHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\MemoryPeakUsageProcessor;
use Monolog\Processor\MemoryUsageProcessor;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;

/**
 * Интерфейс для записи журнала выполнения.
 */
final class AppLogger
{
    /** @var Level[] Уровни ведения журнала. */
    private const Levels = [
        Level::Debug,
        Level::Info,
        Level::Notice,
        Level::Warning,
    ];

    private const ValidLogLevels = [
        'DEBUG',
        'INFO',
        'NOTICE',
        'WARNING',
    ];

    private static int $logMaxSize  = 2097152;
    private static int $logMaxCount = 5;

    public static function create(?string $logFile = null, Level $level = Level::Info): LoggerInterface
    {
        $logger = new Logger(name: 'webtlo');

        // Автоматическая подстановка значений, согласно PSR-3.
        $logger->pushProcessor(new PsrLogMessageProcessor(removeUsedContextFields: true));

        // Добавим в данные об использовании памяти.
        if ($level === Level::Debug) {
            $logger->pushProcessor(new MemoryPeakUsageProcessor());
            $logger->pushProcessor(new MemoryUsageProcessor());
        }

        // Запись в единый файл всего webtlo.
        $logger->pushHandler(new StreamHandler(self::getLogFile(filename: 'webtlo.log')));

        // Запись в error_log.
        $logger->pushHandler(new ErrorLogHandler(level: Level::Error));

        // Запись в legacy-logger для вывода на фронт.
        $logger->pushHandler(self::getLegacyLogger(level: $level));

        // Запись в заданный файл.
        if ($logFile !== null) {
            $logger->pushHandler(self::getFileHandler(filename: $logFile, level: $level));
        }

        return $logger;
    }

    /** Запись логов в конкретный файл. */
    private static function getFileHandler(string $filename, Level $level): HandlerInterface
    {
        $logFile = self::getLogFile(filename: $filename);

        $format = "%datetime% %level_name%: %message% %context%\n";

        $formatter = new LineFormatter(format: $format, dateFormat: 'd.m.Y H:i:s');
        $formatter->ignoreEmptyContextAndExtra();

        return (new StreamHandler(stream: $logFile, level: $level))->setFormatter(formatter: $formatter);
    }

    private static function getLegacyLogger(Level $level): HandlerInterface
    {
        $formatter = new LineFormatter(format: '%level_name%: %message% %context%');
        $formatter->ignoreEmptyContextAndExtra();

        return (new LogHandler(level: $level))->setFormatter(formatter: $formatter);
    }

    /**
     * Создать файл для логов.
     */
    private static function getLogFile(string $filename): string
    {
        $logDir = Helper::getLogDir();
        Helper::makeDirRecursive(path: $logDir);

        $filePath = $logDir . DIRECTORY_SEPARATOR . $filename;
        self::logRotate(filename: $filePath);

        return $filePath;
    }

    private static function logRotate(string $filename): void
    {
        $rotation = new Rotation(options: [
            'files'    => self::$logMaxCount,
            'min-size' => self::$logMaxSize,
        ]);

        $rotation->rotate(filename: $filename);
    }

    public static function getLogLevel(string $level): Level
    {
        // Convert the input log level to uppercase
        $upperLogLevel = strtoupper($level);

        // Check if the converted log level is in the array of valid log levels
        if (in_array($upperLogLevel, self::ValidLogLevels, true)) {
            // If valid, return the corresponding Level
            return Level::fromName($upperLogLevel);
        }

        // If invalid, return the default log level
        return Level::Info;
    }

    public static function getSelectOptions(string $optionFormat, string $level): string
    {
        $selected = self::getLogLevel($level);

        $options = array_map(function($level) use ($optionFormat, $selected) {
            $name = ucfirst(strtolower($level->getName()));

            return sprintf($optionFormat, $name, $level === $selected ? 'selected' : '', $name);
        }, self::Levels);

        return implode('', $options);
    }
}
