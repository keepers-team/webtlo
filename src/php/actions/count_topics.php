<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Legacy\Db;
use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\Module\TopicDetails;

$result = [];
try {
    App::init();

    // Посчитаем количество раздач без имени и их общее количество в БД.
    $result['unnamed'] = TopicDetails::countUnnamed();
    $result['total']   = Db::select_count('Topics');

    $result['current'] = $result['total'] - $result['unnamed'];
} catch (Exception $e) {
    Log::append($e->getMessage());
}

$result['log'] = Log::get();

echo json_encode($result, JSON_UNESCAPED_UNICODE);
