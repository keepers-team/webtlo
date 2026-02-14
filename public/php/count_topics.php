<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Storage\Table\Topics;

$result = [];

// Подключаем контейнер.
$app = App::create();
$log = $app->getLogger();

try {
    /** @var Topics $topics */
    $topics = $app->get(Topics::class);

    // Посчитаем количество раздач без имени и их общее количество в БД.
    $result['unnamed'] = $topics->countUnnamed();
    $result['total']   = $topics->countTotal();

    $result['current'] = $result['total'] - $result['unnamed'];
} catch (Exception $e) {
    $log->error($e->getMessage());
}

$result['log'] = $app->getLoggerRecords();

echo json_encode($result, JSON_UNESCAPED_UNICODE);
