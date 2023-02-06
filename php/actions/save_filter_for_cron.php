<?php

include_once dirname(__FILE__) . '/../classes/log.php';
include_once dirname(__FILE__) . '/../classes/filter.php';
try {
    // полный путь до файла для сохранения параметров фильтра для cron
    $filter = new Filter();

    $result = $filter->writeFilterToFile($_POST['forum-id'], $_POST['filter-options']);
    if ($result === false) {
        Log::append('Не удалось сохранить параметры фильтра в файл');
    } else {
        Log::append('Параметры фильтра успешно сохранены в файл');
    }
    echo json_encode(
        [
            'log' => Log::get()
        ]
    );
} catch (Exception $e) {
    Log::append($e->getMessage());
    echo json_encode(
        [
            'log' => Log::get()
        ]
    );
}