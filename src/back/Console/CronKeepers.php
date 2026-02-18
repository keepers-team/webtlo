<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Console;

use KeepersTeam\Webtlo\Update\KeepersReports;

/**
 * Запуск обновления списка хранителей строго из планировщика.
 *
 * На возможность выполнения влияет опция "Автоматизация и дополнительные настройки" > "[update.php, keepers.php]".
 */
final class CronKeepers
{
    public function __construct(private readonly KeepersReports $keepers) {}

    public function run(): void
    {
        $this->keepers->update();
    }
}
