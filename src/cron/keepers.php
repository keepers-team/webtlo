<?php

try {
    // файл лога
    $logFile = "keepers.log";
    $checkEnabledCronAction = 'update';
    // дёргаем скрипт
    include_once dirname(__FILE__) . '/../php/common/keepers.php';
    // записываем в лог
    Log::write($logFile);
} catch (Exception $e) {
    Log::append($e->getMessage());
    Log::write($logFile);
}
