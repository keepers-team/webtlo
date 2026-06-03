<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\Enum\LogFile;
use KeepersTeam\Webtlo\Logger\MemoryLoggerHandler as Log;

if (empty($_POST['log_file'])) {
    return;
}

/**
 * Попытка получить записи журнала по названию лог-файла.
 */
$logFile = LogFile::tryFrom((string) $_POST['log_file']);
if ($logFile === null) {
    return;
}

try {
    $logPath = $logFile->getFilePath();

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
