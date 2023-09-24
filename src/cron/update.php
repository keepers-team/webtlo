<?php

try {
    // файл лога
    $logFile = "update.log";
    $checkEnabledCronAction = 'update';
    // дёргаем скрипт
    include_once dirname(__FILE__) . '/../php/common/update.php';
    // записываем в лог
    Log::write($logFile);
} catch (Exception $e) {
    Log::append($e->getMessage());
    Log::write($logFile);
}
