<?php

use KeepersTeam\Webtlo\Module\TopicDetails;

$result = [];
try {
    include_once dirname(__FILE__) . '/../common.php';

    // Посчитаем количество раздач без имени и их общее количество в БД.
    $result['unnamed'] = TopicDetails::countUnnamed();
    $result['total']   = Db::select_count('Topics');

    $result['current'] = $result['total'] - $result['unnamed'];
} catch (Exception $e) {
    Log::append($e->getMessage());
}

$result['log'] = Log::get();

echo json_encode($result, JSON_UNESCAPED_UNICODE);