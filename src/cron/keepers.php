<?php

require __DIR__ . '/../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Update\KeepersReports;

try {
    // Инициализируем контейнер.
    $app = AppContainer::create('keepers.log');
    $log = $app->getLogger();

    $config = $app->getLegacyConfig();

    /** @var KeepersReports $keepersReports */
    $keepersReports = $app->get(KeepersReports::class);
    // Запускаем получение данных, с признаком "по расписанию".
    $keepersReports->updateReports(config: $config, schedule: true);

    $log->info('-- DONE --');
} catch (Throwable $e) {
    if (isset($log)) {
        $log->error($e->getMessage());
    }
}
