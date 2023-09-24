<?php

try {
    // файл лога
    $logFile = "reports.log";
    $checkEnabledCronAction = 'reports';
    // дёргаем скрипт
    include_once dirname(__FILE__) . '/../php/common/reports.php';
    // записываем в лог
    Log::write($logFile);
} catch (Exception $e) {
    Log::append($e->getMessage());
    Log::write($logFile);
}
