<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use KeepersTeam\Webtlo\Action\TopicControl;
use KeepersTeam\Webtlo\App;

/**
 * Запуск регулировки раздач в торрент-клиентах.
 *
 * На возможность выполнения влияет опция "Автоматизация и дополнительные настройки" > "[control.php]".
 */
try {
    // Инициализируем контейнер.
    $app = App::create('control.log');
    $log = $app->getLogger();

    // Проверяем возможность запуска регулировки.
    if (!$app->getAutomation()->isActionEnabled(action: 'control')) {
        $log->notice('[Control] Автоматическая регулировка раздач отключена в настройках.');

        return;
    }

    /** @var TopicControl $topicControl */
    $topicControl = $app->get(TopicControl::class);
    $topicControl->process();
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
