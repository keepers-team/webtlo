<?php

use KeepersTeam\Webtlo\Legacy\Log;

$reports_result = [
    'result' => '',
];
try {
    // дёргаем скрипт
    // include_once dirname(__FILE__) . '/../common/reports.php';
    include_once dirname(__FILE__) . '/../common/reports_via_api.php';
} catch (Exception $e) {
    Log::append($e->getMessage());
    $reports_result['result'] = 'В процессе отправки отчётов были ошибки. ' .
        'Для получения подробностей обратитесь к журналу событий.';
}
// выводим лог
$reports_result['log'] = Log::get();

echo json_encode($reports_result, JSON_UNESCAPED_UNICODE);
