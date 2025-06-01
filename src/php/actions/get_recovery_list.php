<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;

// Подключаем контейнер.
$app = App::create();
$log = $app->getLogger();

try {
    $db  = $app->getDataBase();

    // получение настроек
    $cfg = $app->getLegacyConfig();

    $counters = $db->query(
        'SELECT tu.status, COUNT(1) AS quantity
        FROM TopicsUnregistered AS tu
            LEFT JOIN Torrents AS tr ON tr.info_hash = tu.info_hash
        WHERE tr.done = 1.0
            AND tu.priority IS NOT ("низкий")
        GROUP BY tu.status
        ORDER BY 2 DESC'
    );

    if (count($counters)) {
        $table = '';
        foreach ($counters as $row) {
            $table .= vsprintf('<tr><td>%s</td><td>%s</td></tr>', $row);
        }
        echo "<table><thead><th>Статус</th><th>Количество</th></thead>$table</table></br>";
    }

    $allowedStatuses = [
        'обновлено (проверено)',
        'обновлено (не проверено)',
        'обновлено (недооформлено)',
        'обновлено (временная)',
        'обновлено (сомнительно)',
    ];

    if (!empty($_GET['statuses'])) {
        $allowedStatuses = explode(',', $_GET['statuses']);
    }

    $in = str_repeat('?,', count($allowedStatuses) - 1) . '?';

    $topics = $db->query(
        'SELECT DISTINCT
            tr.topic_id,
            tu.status,
            tu.transferred_from,
            tu.transferred_to,
            tu.transferred_by_whom
        FROM TopicsUnregistered AS tu
        LEFT JOIN Torrents AS tr ON tr.info_hash = tu.info_hash
        WHERE tr.done = 1.0
            AND tu.priority IS NOT ("низкий")
            AND tu.status IN (' . $in . ')',
        $allowedStatuses,
    );

    if (!count($topics)) {
        echo 'Нет разрегистрированных раздач доступных к восстановлению.';

        return;
    }

    $output = [];
    foreach ($topics as $topic) {
        $categoryTitle = empty($topic['transferred_from']) ? $topic['transferred_to'] : $topic['transferred_from'];

        $currentCategory = explode(' » ', $topic['transferred_to']);
        $currentCategory = $currentCategory[0];

        $output[$currentCategory][] = sprintf(
            '%s | [url=viewtopic.php?t=%d]%d[/url] | %s | %s',
            $categoryTitle,
            (int) $topic['topic_id'],
            (int) $topic['topic_id'],
            $topic['status'],
            $topic['transferred_by_whom']
        );

        unset($topic);
    }
    unset($topics);

    ksort($output, SORT_NATURAL);

    foreach ($output as $categoryName => $categoryData) {
        asort($categoryData, SORT_NATURAL);

        echo '[b]' . $categoryName . '[/b][hr]</br>';
        foreach ($categoryData as $topicData) {
            echo $topicData . '</br>';
        }
        echo '</br>';
    }
} catch (Exception $e) {
    $log->error($e->getMessage());

    echo $app->getLoggerRecords();
}
