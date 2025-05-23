<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\Helper;

if (isset($_POST['log_file'])) {
    $log_file = $_POST['log_file'];
}

if (empty($log_file)) {
    return;
}

$log_file = Helper::getLogDir() . DIRECTORY_SEPARATOR . $log_file . '.log';

if (file_exists($log_file)) {
    $fh = fopen($log_file, 'w');

    if ($fh !== false) {
        fclose($fh);
    }
}
