<?php

if (isset($_POST['log_file'])) {
    $log_file = $_POST['log_file'];
}

if (empty($log_file)) {
    return;
}

$log_file = dirname(__FILE__) . "/../../data/logs/$log_file.log";

if (file_exists($log_file)) {
    $fh = fopen($log_file, 'w');
    fclose($fh);
}
