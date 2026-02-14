<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\Helper;

if (isset($_POST['log_file'])) {
    $log_file = $_POST['log_file'];
}

if (empty($log_file)) {
    return;
}

try {
    $logPath = Helper::getStorageLogsPath($log_file . '.log');

    if (file_exists($logPath)) {
        $fh = fopen($logPath, 'w');

        if ($fh !== false) {
            fclose($fh);
        }
    }
} catch (Throwable $e) {
    echo $e->getMessage();
}
