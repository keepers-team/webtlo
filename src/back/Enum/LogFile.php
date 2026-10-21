<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Enum;

use KeepersTeam\Webtlo\Helper;

/**
 * Доступные к использованию имена файлов журнала.
 */
enum LogFile: string
{
    /**
     * Основной файл для всех записей журнала.
     */
    case Main = 'webtlo';

    /** Записи операций, выводимых во вкладке «Лог». */
    case Default = 'default';

    /**
     * Файлы для каждого из процессов.
     */
    case Update  = 'update';
    case Keepers = 'keepers';
    case Reports = 'reports';
    case Control = 'control';
    case Topics  = 'topics';

    public function getFileName(): string
    {
        return $this->value . '.log';
    }

    /**
     * Пусть к файлу на диске.
     */
    public function getFilePath(): string
    {
        return Helper::getStorageLogsPath(file: $this->getFileName());
    }

    /**
     * Максимальное количество файлов при ротации.
     *
     * @return positive-int
     */
    public function maxFilesCount(): int
    {
        return match ($this) {
            self::Default => 1,
            default       => 5,
        };
    }

    /**
     * Максимальный размер файла при ротации.
     *
     * @return positive-int
     */
    public function maxFileSize(): int
    {
        return match ($this) {
            self::Default => 65536,   // 64 кб
            default       => 2097152, // 2 мб
        };
    }
}
