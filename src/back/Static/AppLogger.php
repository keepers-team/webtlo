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

    public static function create(?string $logFile = null, Level $logLevel = Level::Info): LoggerInterface
    {
        $logger = new Logger('webtlo');

        // Запись в единый файл всего webtlo.
        $logger->pushHandler(new StreamHandler(self::getLogFile('webtlo.log')));

        // Запись в error_log.
        $logger->pushHandler(new ErrorLogHandler(0, Level::Error));

        // Запись в legacy-logger для вывода на фронт.
        $logger->pushHandler(self::getLegacyLogger($logLevel));

        // Автоматическая подстановка значений, согласно PSR-3.
        $logger->pushProcessor(new PsrLogMessageProcessor(null, true));

        // Добавим в данные об использовании памяти.
        if (Level::Debug === $logLevel) {
            $logger->pushProcessor(new MemoryPeakUsageProcessor());
            $logger->pushProcessor(new MemoryUsageProcessor());
        }

        // Запись в заданный файл.
        if (null !== $logFile) {
            $logger->pushHandler(self::getFileHandler($logFile, $logLevel));
        }

        return $logger;
    }

    /** Запись логов в конкретный файл. */
    private static function getFileHandler(string $fileName, Level $logLevel): HandlerInterface
    {
        $logFile = self::getLogFile($fileName);

        $format = "%datetime% %level_name%: %message% %context%\n";

        $formatter = new LineFormatter($format, 'd.m.Y H:i:s');
        $formatter->ignoreEmptyContextAndExtra();

        return (new StreamHandler($logFile, $logLevel))->setFormatter($formatter);
    }

    /** Создать файл для логов. */
    private static function getLogFile(string $fileName): string
    {
        $logDir = Helper::getLogDir();
        Helper::makeDirRecursive($logDir);

        $filePath = $logDir . DIRECTORY_SEPARATOR . $fileName;
        self::logRotate($filePath);

        return $filePath;
    }

    private static function getLegacyLogger(Level $logLevel): HandlerInterface
    {
        $formatter = new LineFormatter('%level_name%: %message% %context%');
        $formatter->ignoreEmptyContextAndExtra();

        return (new LogHandler($logLevel))->setFormatter($formatter);
    }

    private static function logRotate(string $file): void
    {
        $rotation = new Rotation([
            'files'    => self::$logMaxCount,
            'min-size' => self::$logMaxSize,
        ]);

        $rotation->rotate($file);
    }

    public static function getLogLevel(string $logLevel): Level
    {
        // Convert the input log level to uppercase
        $upperLogLevel = strtoupper($logLevel);

        // Check if the converted log level is in the array of valid log levels
        if (in_array($upperLogLevel, self::ValidLogLevels, true)) {
            // If valid, return the corresponding Level
            return Level::fromName($upperLogLevel);
        }

        // If invalid, return the default log level
        return Level::Info;
    }

    public static function getSelectOptions(string $optionFormat, string $logLevel): string
    {
        $selected = self::getLogLevel($logLevel);

        $options = array_map(function($level) use ($optionFormat, $selected) {
            $name = ucfirst(strtolower($level->getName()));

            return sprintf($optionFormat, $name, $level === $selected ? 'selected' : '', $name);
        }, self::Levels);

        return implode('', $options);
    }
}
