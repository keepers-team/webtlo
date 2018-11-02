<?php

// include_once dirname(__FILE__) . '/../common.php';

if (isset($_POST['log_file'])) {
    $log_file = $_POST['log_file'];
}

if (empty($log_file)) {
    return;
}

$log_file = dirname(__FILE__) . "/../../data/logs/$log_file.log";

if (file_exists($log_file)) {
    if ($data = file($log_file)) {
        // последние 3000 строк
        $data = array_reverse(array_slice($data, -3000));
        $data = implode('<br />', $data) . '<br />';
        echo $data;
    }
}
