<?php

$update_result = [
    'result' => '',
];
try {
    // дёргаем скрипт
    include_once dirname(__FILE__) . '/../common/update.php';

    // дёргаем скрипт
    include_once dirname(__FILE__) . '/../common/keepers.php';

} catch (Exception $e) {
    Log::append($e->getMessage());
}
// выводим лог
$update_result['log'] = Log::get();

echo json_encode($update_result, JSON_UNESCAPED_UNICODE);
