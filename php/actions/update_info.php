<?php

try {
    // дёргаем скрипт
    include_once dirname(__FILE__) . '/../common/update.php';

    // дёргаем скрипт
    include_once dirname(__FILE__) . '/../common/keepers.php';

    echo json_encode([
        'log' => Log::get(),
        'result' => '',
    ]);
} catch (Exception $e) {
    Log::append($e->getMessage());
    echo json_encode([
        'log' => Log::get(),
        'result' => "В процессе обновления сведений были ошибки. Для получения подробностей обратитесь к журналу событий.",
    ]);
}
