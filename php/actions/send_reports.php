<?php

try {

    // дёргаем скрипт
    include_once dirname(__FILE__) . '/../common/reports.php';

    // выводим лог
    echo json_encode(
        array(
            'log' => Log::get(),
            'result' => '',
        )
    );

} catch (Exception $e) {

    Log::append($e->getMessage());
    echo json_encode(
        array(
            'log' => Log::get(),
            'result' => "В процессе отправки отчётов были ошибки. Для получения подробностей обратитесь к журналу событий.",
        )
    );

}
