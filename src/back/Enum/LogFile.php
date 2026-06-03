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

    /**
     * Файлы для каждого из процессов.
     */
    case Update  = 'update';
    case Keepers = 'keepers';
    case Reports = 'reports';
    case Control = 'control';

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
}
