<?php

require __DIR__ . '/../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Legacy\Log;

try {
    // Инициализируем контейнер, без имени лога, чтобы записи не двоились от legacy/di.
    AppContainer::create();

    // дёргаем скрипт
    $checkEnabledCronAction = 'update';
    include_once dirname(__FILE__) . '/../php/common/keepers_via_api.php';
} catch (Exception $e) {
    Log::append($e->getMessage());
}

// записываем в лог
Log::write('keepers.log');
