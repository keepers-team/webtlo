<?php

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/api.php';

try {

    if (empty($_GET['term'])) {
        return false;
    }

    $pattern = is_array($_GET['term']) ? $_GET['term'] : array($_GET['term']);

    $q = Db::query_database(
        "SELECT COUNT() FROM Forums",
        array(),
        true,
        PDO::FETCH_COLUMN
    );

    if (empty($q[0])) {
        // дёргаем скрипт
        include_once dirname(__FILE__) . '/../common/forum_tree.php';
    }

    $forums = array();

    foreach ($pattern as $pattern) {
        $pattern = '%' . str_replace(' ', '%', $pattern) . '%';
        $data = Db::query_database(
            "SELECT id AS value, na AS label FROM Forums
            WHERE id LIKE :term OR na LIKE :term ORDER BY na",
            array('term' => $pattern),
            true
        );
        $forums = array_merge_recursive($forums, $data);
    }

    echo json_encode($forums);

} catch (Exception $e) {
    echo json_encode(array(array(
        'label' => $e->getMessage(),
        'value' => -1,
    )));
}
