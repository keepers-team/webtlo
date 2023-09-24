<?php

$control_result = [
    'result' => '',
];
try {
    // дёргаем скрипт
    include_once dirname(__FILE__) . '/../common/control.php';
} catch (Exception $e) {
    Log::append($e->getMessage());
    $control_result['result'] = 'В процессе регулировки раздач были ошибки. ' .
        'Для получения подробностей обратитесь к журналу событий.';
}
// выводим лог
$control_result['log'] = Log::get();

echo json_encode($control_result, JSON_UNESCAPED_UNICODE);
