<?php

try {
    // файл лога
    $logFile = "seeders.log";
    // дёргаем скрипт
    include_once dirname(__FILE__) . '/../php/common/seeders.php';
    // записываем в лог
    Log::write($logFile);
} catch (Exception $e) {
    Log::append($e->getMessage());
    Log::write($logFile);
}
