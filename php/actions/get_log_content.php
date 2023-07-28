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
        $split_value = '-- DONE --';
        // последние 3000 строк
        $data = array_slice($data, -3000);
        $key = 0;
        $temp = [];
        // режем вывод по ключевому слову
        foreach ($data as $row) {
            $temp[$key][] = $row;
            if (strpos($row, $split_value) !== false) {
                $temp[$key][] = '';
                $key++;
            }
        }
        // переворачиваем порядок процессов. Последний - вверху.
        $data = array_merge(...array_reverse($temp));
        $data = implode('<br />', $data) . '<br />';
        echo $data;
    }
}
