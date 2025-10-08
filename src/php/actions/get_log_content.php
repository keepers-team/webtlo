<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Logger\MemoryLoggerHandler as Log;

if (isset($_POST['log_file'])) {
    $log_file = $_POST['log_file'];
}

if (empty($log_file)) {
    return;
}

try {
    $logPath = Helper::getStorageLogsPath($log_file . '.log');

    if (file_exists($logPath)) {
        if ($data = file($logPath)) {
            // Последние 3000 строк.
            $data = array_slice($data, -3000);

            $data = Log::formatRows(rows: $data, replace: true);

            echo $data;
        }
    }
} catch (Throwable $e) {
    echo $e->getMessage();
}
