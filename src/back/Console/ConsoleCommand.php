<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Console;

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Console\Topics\TopicsAdding;
use KeepersTeam\Webtlo\Console\Topics\TopicsDownload;
use KeepersTeam\Webtlo\Enum\LogFile;

/**
 * Список доступных команд планировщика для запуска.
 */
enum ConsoleCommand: string
{
    // Список доступных команд для встроенного планировщика.
    case Control = 'cron:control';
    case Keepers = 'cron:keepers';
    case Reports = 'cron:reports';
    case Update  = 'cron:update';

    // Список команд, которые может вызвать Adder.
    case AdderKeepers = 'adder:keepers';
    case AdderReports = 'adder:reports';
    case AdderUpdate  = 'adder:update';

    // Скачивание торрент-файлов.
    case TopicsAdding   = 'topics:adding';
    case TopicsDownload = 'topics:download';

    public function logFile(): LogFile
    {
        return match ($this) {
            self::Control        => LogFile::Control,
            self::AdderKeepers,
            self::Keepers        => LogFile::Keepers,
            self::AdderReports,
            self::Reports        => LogFile::Reports,
            self::AdderUpdate,
            self::Update         => LogFile::Update,
            self::TopicsAdding,
            self::TopicsDownload => LogFile::Topics,
        };
    }

    public function run(App $app, ConsoleInput $input): void
    {
        match ($this) {
            self::Control        => $app->get(CronControl::class)->run(),
            self::AdderKeepers,
            self::Keepers        => $app->get(CronKeepers::class)->run(),
            self::AdderReports,
            self::Reports        => $app->get(CronReports::class)->run(),
            self::AdderUpdate,
            self::Update         => $app->get(CronUpdate::class)->run(),
            self::TopicsAdding   => $app->get(TopicsAdding::class)->run(input: $input),
            self::TopicsDownload => $app->get(TopicsDownload::class)->run(input: $input),
        };
    }

    /**
     * @return array{}|string[]
     */
    public function arguments(): array
    {
        return match ($this) {
            self::TopicsAdding   => [
                'listingId',
                'filterName',
            ],

            self::TopicsDownload => [
                'listingId',
                'filterName',
                'replacePasskey',
            ],

            default              => [],
        };
    }
}
