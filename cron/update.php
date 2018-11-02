<?php

try {

    // файл лога
    $filelog = "update.log";

    // дёргаем скрипт
    include_once dirname(__FILE__) . '/../php/common/update.php';

    // записываем в лог
    Log::write($filelog);

} catch (Exception $e) {

    Log::append($e->getMessage());
    Log::write($filelog);

}
