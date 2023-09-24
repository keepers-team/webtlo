<?php

$result = [];
try {
    // Обновление раздач за раз. Меньшее число, для наглядности.
    $updateDetailsPerRun = 1500;

    include_once dirname(__FILE__) . '/../common/update_details.php';
} catch (Exception $e) {
    Log::append($e->getMessage());
}

$result['log'] = Log::get();

echo json_encode($result, JSON_UNESCAPED_UNICODE);