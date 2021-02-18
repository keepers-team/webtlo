<?php

try {
    // дёргаем скрипт
    include_once dirname(__FILE__) . '/../common/keepers.php';

    // дёргаем скрипт
    include_once dirname(__FILE__) . '/../common/update.php';

    echo json_encode(array(
        'log' => Log::get(),
        'result' => '',
    ));
} catch (Exception $e) {
    Log::append($e->getMessage());
    echo json_encode(array(
        'log' => Log::get(),
        'result' => "В процессе обновления сведений были ошибки. Для получения подробностей обратитесь к журналу событий.",
    ));
}
