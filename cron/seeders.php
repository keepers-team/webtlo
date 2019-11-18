<?php

try {

    // файл лога
    $filelog = "seeders.log";

    // дёргаем скрипт
    include_once dirname(__FILE__) . '/../php/common/seeders.php';

    // записываем в лог
    Log::write($filelog);

} catch (Exception $e) {

    Log::append($e->getMessage());
    Log::write($filelog);

}
