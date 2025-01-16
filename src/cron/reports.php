<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use KeepersTeam\Webtlo\Action\SendReports;
use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Helper;

try {
    // Инициализируем контейнер.
    $app = App::create('reports.log');
    $log = $app->getLogger();

    $config = $app->getLegacyConfig();

    // Проверяем возможность запуска обновления.
    if (!Helper::isScheduleActionEnabled(config: $config, action: 'reports')) {
        $log->notice('[Reports] Автоматическая отправка отчётов отключена в настройках.');

        return;
    }

    /** @var SendReports $action Отправка отчётов. */
    $action = $app->get(SendReports::class);
    $action->process();
} catch (RuntimeException $e) {
    if (isset($log)) {
        $log->warning($e->getMessage());
    }
} catch (Throwable $e) {
    if (isset($log)) {
        $log->error($e->getMessage());
    }
} finally {
    if (isset($log)) {
        $log->info('-- DONE --');
    }
}
