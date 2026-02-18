<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Console;

use KeepersTeam\Webtlo\App;

/**
 * Список доступных команд планировщика для запуска.
 */
enum CronCommand: string
{
    case Control = 'cron:control';
    case Keepers = 'cron:keepers';
    case Reports = 'cron:reports';
    case Update  = 'cron:update';

    public function logFile(): string
    {
        return strtolower($this->name) . '.log';
    }

    public function run(App $app): void
    {
        match ($this) {
            self::Control => $app->get(CronControl::class)->run(),
            self::Keepers => $app->get(CronKeepers::class)->run(),
            self::Reports => $app->get(CronReports::class)->run(),
            self::Update  => $app->get(CronUpdate::class)->run(),
        };
    }

    public function cliUsage(): string
    {
        return "php bin/webtlo {$this->value}";
    }
}
