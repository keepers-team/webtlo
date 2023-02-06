<?php

try {
    // файл лога
    $logFile = "auto_add.log";
    // дёргаем скрипт
    include_once dirname(__FILE__) . '/../php/common/auto_add.php';
    // записываем в лог
    Log::write($logFile);
} catch (Exception $e) {
    Log::append($e->getMessage());
    Log::write($logFile);
}
