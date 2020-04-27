<?php

try {
    // дёргаем скрипт
    include_once dirname(__FILE__) . '/../common/control.php';

    echo json_encode(array(
        'log' => Log::get(),
        'result' => '',
    ));
} catch (Exception $e) {
    Log::append($e->getMessage());
    $result = 'В процессе регулировки раздач были ошибки. ' .
        'Для получения подробностей обратитесь к журналу событий.';
    echo json_encode(array(
        'log' => Log::get(),
        'result' => $result,
    ));
}
