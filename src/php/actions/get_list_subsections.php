<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Legacy\Db;

try {
    if (empty($_GET['term'])) {
        return false;
    }

    App::init();

    $patterns = is_array($_GET['term']) ? $_GET['term'] : [$_GET['term']];

    if (empty(Db::select_count('Forums'))) {
        // дёргаем скрипт
        include_once dirname(__FILE__) . '/../common/forum_tree.php';
    }

    $forums = [];
    foreach ($patterns as $pattern) {
        if (!is_numeric($pattern)) {
            $pattern = '%' . str_replace(' ', '%', $pattern) . '%';
        }
        $data = Db::query_database(
            "SELECT id AS value, name AS label FROM Forums
            WHERE size > 0 AND (id LIKE :term OR name LIKE :term) ORDER BY LOWER(name)",
            ['term' => $pattern],
            true
        );
        $forums = array_merge_recursive($forums, $data);
    }

    echo json_encode($forums, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([[
        'label' => $e->getMessage(),
        'value' => -1,
    ]]);
}
