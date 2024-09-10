<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use KeepersTeam\Webtlo\Action\TopicControl;
use KeepersTeam\Webtlo\AppContainer;

try {
    // Инициализируем контейнер.
    $app = AppContainer::create('control.log');
    $log = $app->getLogger();

    $config = $app->getLegacyConfig();

    /** @var TopicControl $topicControl */
    $topicControl = $app->get(TopicControl::class);
    // Запускаем регулировку раздач, с признаком "по расписанию".
    $topicControl->process(config: $config, schedule: true);
} catch (RuntimeException $e) {
    if (isset($log)) {
        $log->warning($e->getMessage());
    }
} catch (Throwable $e) {
    if (isset($log)) {
        $log->error($e->getMessage());
    }
}
