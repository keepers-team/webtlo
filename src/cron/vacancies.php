<?php

try {
    // файл лога
    $logFile = "vacancies.log";
    // дёргаем скрипт
    include_once dirname(__FILE__) . '/../php/common/vacancies.php';
    // записываем в лог
    Log::write($logFile);
} catch (Exception $e) {
    Log::append($e->getMessage());
    Log::write($logFile);
}
