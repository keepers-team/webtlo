<?php

include_once dirname(__FILE__) . '/../classes/log.php';
include_once dirname(__FILE__) . '/../classes/filter.php';
try {
    // полный путь до файла для сохранения параметров фильтра для cron
    $filter = new Filter();
    $result = $filter->readFilterFromFile();
    if (empty($result[$_POST['forum-id']])) {
        Log::append('Не удалось прочитать параметры фильтра из файла для подраздела:' . $_POST['forum-id']);
    } else {
        Log::append('Параметры фильтра успешно прочитаны из файла для подраздела:' . $_POST['forum-id']);
    }
    echo json_encode(
        [
            'log' => Log::get(),
            'result' =>  $result[$_POST['forum-id']],
        ]
    );
} catch (Exception $e) {
    Log::append($e->getMessage());
    echo json_encode(
        [
            'log' => Log::get(),
            'result' => $result[$_POST['forum-id']],
        ]
    );
}