<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use KeepersTeam\Webtlo\Console\ConsoleKernel;

/**
 * Запуск обновления списка хранителей строго из планировщика.
 *
 * На возможность выполнения влияет опция "Автоматизация и дополнительные настройки" > "[update.php, keepers.php]".
 *
 * @deprecated Use bin/webtlo instead
 *
 * @TODO       remove in 4.2
 */
$kernel = new ConsoleKernel();

exit($kernel->handle([__FILE__, 'cron:update']));
