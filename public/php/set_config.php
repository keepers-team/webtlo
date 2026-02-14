<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Helper;

$app = App::create();
$log = $app->getLogger();

try {
    $request = json_decode((string) file_get_contents('php://input'), true);

    // парсим настройки
    $cfg = [];
    if (isset($request['cfg'])) {
        parse_str($request['cfg'], $cfg);
    }
    if (empty($cfg)) {
        throw new RuntimeException('Настройки не переданы. Нечего сохранять.');
    }
    $cfg = Helper::convertKeysToString($cfg);

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
} catch (Throwable $e) {
    $log->error($e->getMessage());
} finally {
    $log->info('-- DONE --');
}

echo json_encode(
    ['log' => $app->getLoggerRecords()],
    JSON_UNESCAPED_UNICODE
);
