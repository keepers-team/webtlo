<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;

try {
    // Инициализируем контейнер.
    $app = AppContainer::create('control.log');
    $log = $app->getLogger();

    // дёргаем скрипт
    $checkEnabledCronAction = 'control';
    include_once dirname(__FILE__) . '/../php/common/control.php';
} catch (Throwable $e) {
    if (isset($log)) {
        $log->error($e->getMessage());
    }
}
