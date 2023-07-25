<?php

try {
    // файл лога
    $logFile = "control.log";
    $checkEnabledCronAction = 'control';
    // дёргаем скрипт
    include_once dirname(__FILE__) . '/../php/common/control.php';
    // записываем в лог
    Log::write($logFile);
} catch (Exception $e) {
    Log::append($e->getMessage());
    Log::write($logFile);
}
