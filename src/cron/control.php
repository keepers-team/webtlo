<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use KeepersTeam\Webtlo\Action\TopicControl;
use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Helper;

/**
 * Запуск регулировки раздач в торрент-клиентах.
 *
 * На возможность выполнения влияет опция "Автоматизация и дополнительные настройки" > "[control.php]".
 */

try {
    // Инициализируем контейнер.
    $app = AppContainer::create('control.log');
    $log = $app->getLogger();

    $config = $app->getLegacyConfig();

    // Проверяем возможность запуска регулировки.
    if (!Helper::isScheduleActionEnabled(config: $config, action: 'control')) {
        $log->notice('[Control] Автоматическая регулировка раздач отключена в настройках.');

        return;
    }

    /** @var TopicControl $topicControl */
    $topicControl = $app->get(TopicControl::class);
    $topicControl->process(config: $config);
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
