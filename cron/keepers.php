<?php

try {

    // файл лога
    $filelog = "keepers.log";

    // дёргаем скрипт
    include_once dirname(__FILE__) . '/../php/common/keepers.php';

    // записываем в лог
    Log::write($filelog);

} catch (Exception $e) {

    Log::append($e->getMessage());
    Log::write($filelog);

}
