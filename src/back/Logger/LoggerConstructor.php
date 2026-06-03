<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Logger;

use Cesargb\Log\Rotation;
use KeepersTeam\Webtlo\Enum\LogFile;
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
use Throwable;

/**
 * Интерфейс для записи журнала выполнения.
 */
final class LoggerConstructor
{
    private const LogDateFormat = 'd.m.Y H:i:s';

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

    public static function create(?LogFile $logFile = null, Level $level = Level::Info): LoggerInterface
    {
        $appLogFile = LogFile::Main;

        $logger = new Logger(name: $appLogFile->value);

        // Автоматическая подстановка значений, согласно PSR-3.
        $logger->pushProcessor(new PsrLogMessageProcessor(removeUsedContextFields: true));

        // Запись в memory-logger для вывода на фронт.
        $logger->pushHandler(self::getMemoryLogger(level: $level));

        // Пробуем создать файлы журналов и писать в них.
        // Если не удалось, то пишем всё в ErrorLogHandler => error_log.
        try {
            // Запись в единый файл всего webtlo.
            $logger->pushHandler(
                new StreamHandler(self::createLogFile(logFile: $appLogFile))
            );

            // Запись в заданный файл.
            if ($logFile !== null) {
                $logger->pushHandler(
                    self::getFileHandler(logFile: $logFile, level: $level)
                );
            }

            // Запись в error_log.
            $logger->pushHandler(new ErrorLogHandler(level: Level::Error));

            // Добавим в данные об использовании памяти.
            if ($level === Level::Debug) {
                $logger->pushProcessor(new MemoryPeakUsageProcessor());
                $logger->pushProcessor(new MemoryUsageProcessor());
            }
        } catch (Throwable $e) {
            // Пишем всё в error_log и выводим ошибку доступа.
            $logger->pushHandler(new ErrorLogHandler(level: Level::Debug));
            $logger->error($e->getMessage());
        }

        return $logger;
    }

    /**
     * Запись логов в конкретный файл.
     */
    private static function getFileHandler(LogFile $logFile, Level $level): HandlerInterface
    {
        $logFilePath = self::createLogFile(logFile: $logFile);

        $format = "%datetime% %level_name%: %message% %context%\n";

        $formatter = new LineFormatter(format: $format, dateFormat: self::LogDateFormat);
        $formatter->ignoreEmptyContextAndExtra();

        return (new StreamHandler(stream: $logFilePath, level: $level))->setFormatter(formatter: $formatter);
    }

    private static function getMemoryLogger(Level $level): HandlerInterface
    {
        $format = '%datetime% %level_name%: %message% %context%';

        $formatter = new LineFormatter(format: $format, dateFormat: self::LogDateFormat);
        $formatter->ignoreEmptyContextAndExtra();

        return (new MemoryLoggerHandler(level: $level))->setFormatter(formatter: $formatter);
    }

    /**
     * Создать файл для логов.
     */
    private static function createLogFile(LogFile $logFile): string
    {
        // Путь к файлу журнала, с созданием каталогов.
        $filePath = $logFile->getFilePath();

        // Ротация файла журнала, если нужна.
        self::logRotate(filename: $filePath);

        // Создаём файл, если его нет.
        if (!file_exists($filePath)) {
            touch($filePath);
        }

        return $filePath;
    }

    private static function logRotate(string $filename): void
    {
        if (!file_exists($filename)) {
            return;
        }

        $rotation = new Rotation(options: [
            'files'    => self::$logMaxCount,
            'min-size' => self::$logMaxSize,
            'then'     => static fn(string $rotated, string $origin): bool => touch($origin),
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
