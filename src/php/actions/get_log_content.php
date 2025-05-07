<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Legacy\Log;

if (isset($_POST['log_file'])) {
    $log_file = $_POST['log_file'];
}

if (empty($log_file)) {
    return;
}

$log_file = Helper::getLogDir() . DIRECTORY_SEPARATOR . $log_file . ".log";

if (file_exists($log_file)) {
    if ($data = file($log_file)) {
        // Последние 3000 строк.
        $data = array_slice($data, -3000);

        $data = Log::formatRows(rows: $data, replace: true);

        echo $data;
    }
}
