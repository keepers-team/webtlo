<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\Update\ForumTree;

try {
    if (empty($_GET['term'])) {
        return false;
    }

    $app = App::create();

    /** @var DB $db */
    $db = $app->get(DB::class);

    $patterns = is_array($_GET['term'])
        ? $_GET['term']
        : explode(';', (string) $_GET['term']);

    if (empty($db->selectRowsCount('Forums'))) {
        /** @var ForumTree $forumTree Обновляем дерево подразделов. */
        $forumTree = $app->get(ForumTree::class);
        $forumTree->update();
    }

    $forums = [];
    foreach ($patterns as $pattern) {
        $pattern = trim((string) $pattern);

        if (!is_numeric($pattern)) {
            $pattern = '%' . str_replace(' ', '%', $pattern) . '%';
        }

        $data = $db->query(
            sql  : '
                SELECT id AS value, name AS label FROM Forums
                WHERE size > 0 AND (id LIKE :term OR name LIKE :term) ORDER BY LOWER(name)
             ',
            param: ['term' => (string) $pattern],
        );

        $forums = array_merge_recursive($forums, $data);
    }

    echo json_encode($forums, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        [
            'label' => $e->getMessage(),
            'value' => -1,
        ],
    ]);
}
