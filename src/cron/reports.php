<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use KeepersTeam\Webtlo\Action\SendKeeperReports;
use KeepersTeam\Webtlo\App;

try {
    // Инициализируем контейнер.
    $app = App::create('reports.log');
    $log = $app->getLogger();

    // Проверяем возможность запуска обновления.
    if (!$app->getAutomation()->isActionEnabled(action: 'reports')) {
        $log->notice('[Reports] Автоматическая отправка отчётов отключена в настройках.');

        return;
    }

    /** @var SendKeeperReports $action Отправка отчётов. */
    $action = $app->get(SendKeeperReports::class);
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
