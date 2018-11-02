<?php

try {

    // файл лога
    $filelog = "reports.log";

    // дёргаем скрипт
    include_once dirname(__FILE__) . '/../php/common/reports.php';

    // записываем в лог
    Log::write($filelog);

} catch (Exception $e) {

    Log::append($e->getMessage());
    Log::write($filelog);

}
