<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\Storage\Table\Topics;

$result = [];

try {
    $app = App::create();
    $log = $app->getLogger();

    /** @var Topics $topics */
    $topics = $app->get(Topics::class);

    // Посчитаем количество раздач без имени и их общее количество в БД.
    $result['unnamed'] = $topics->countUnnamed();
    $result['total']   = $topics->countTotal();

    $result['current'] = $result['total'] - $result['unnamed'];
} catch (Exception $e) {
    if (isset($log)) {
        $log->error($e->getMessage());
    } else {
        Log::append($e->getMessage());
    }
}

$result['log'] = Log::get();

echo json_encode($result, JSON_UNESCAPED_UNICODE);
