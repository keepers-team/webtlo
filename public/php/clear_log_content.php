<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\Enum\LogFile;

if (empty($_POST['log_file'])) {
    return;
}

/**
 * Попытка очистить записи журнала по названию лог-файла.
 */
$logFile = LogFile::tryFrom((string) $_POST['log_file']);
if ($logFile === null) {
    return;
}

try {
    $logPath = $logFile->getFilePath();

    if (file_exists($logPath)) {
        $fh = fopen($logPath, 'w');

        if ($fh !== false) {
            fclose($fh);
        }
    }
} catch (Throwable $e) {
    echo $e->getMessage();
}
