<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Console;

use KeepersTeam\Webtlo\Action\SendKeeperReports;

/**
 * Запуск отправки отчётов.
 *
 * На возможность выполнения влияет опция "Автоматизация и дополнительные настройки" > "[cron:reports]".
 */
final class CronReports
{
    public function __construct(private readonly SendKeeperReports $reports) {}

    public function run(): void
    {
        $this->reports->process();
    }
}
