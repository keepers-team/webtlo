<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Legacy\Log;

try {
    $app = AppContainer::create();
    $log = $app->getLogger();

    $request = json_decode(file_get_contents('php://input'), true);

    // парсим настройки
    $cfg = [];
    if (isset($request['cfg'])) {
        parse_str($request['cfg'], $cfg);
    }
    if (empty($cfg)) {
        throw new RuntimeException('Настройки не переданы. Нечего сохранять.');
    }

    $settings = $app->getSettings();

    $forums  = $request['forums'] ?? [];
    $clients = $request['tor_clients'] ?? [];

    // Записываем настройки.
    $saveResult = $settings->update($cfg, $forums, $clients);
    if ($saveResult) {
        $log->info('Настройки успешно сохранены в файл.');
    } else {
        $log->warning('Не удалось сохранить настройки в файл.');
    }

    // Сделаем копию настроек, убрав приватные данные.
    $cloneResult = $settings->makePublicCopy('config_public.ini');
    if ($cloneResult) {
        $log->info('Публичная копия настроек сохранена успешно.');
    } else {
        $log->warning('Не удалось сохранить публичную копию настроек.');
    }

    $log->info('-- DONE --');
} catch (Throwable $e) {
    if (isset($log)) {
        $log->error($e->getMessage());
    } else {
        Log::append($e->getMessage());
    }
}

echo json_encode(['log' => Log::get()], JSON_UNESCAPED_UNICODE);
