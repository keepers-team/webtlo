<?php

try {
    include dirname(__FILE__) . '/../common.php';

    $cfg = get_settings();

    $output = [];

    $allowedStatuses = [
        'проверено',
        'не проверено',
        'недооформлено',
        // 'временная',
        'сомнительно'
    ];

    $in = str_repeat('?,', count($allowedStatuses) - 1) . '?';

    $topics = Db::query_database(
        'SELECT
            Torrents.topic_id,
            status,
            transferred_from,
            transferred_to,
            transferred_by_whom
        FROM TopicsUnregistered
        LEFT JOIN Torrents ON Torrents.info_hash = TopicsUnregistered.info_hash
        WHERE
            priority IS NOT "низкий"
            AND Torrents.done = 1.0
            AND status IN (' . $in . ')',
        $allowedStatuses,
        true
    );

    foreach ($topics as $topic) {
        $currentCategory = explode(' » ', $topic['transferred_to']);
        $categoryTitle = empty($topic['transferred_from']) ? $topic['transferred_to'] : $topic['transferred_from'];
        $output[$currentCategory[0]][] = $categoryTitle  . ' | [url=viewtopic.php?t=' . $topic['topic_id'] . ']' .
            $topic['topic_id'] . '[/url] | ' . $topic['status'] . ' | ' . $topic['transferred_by_whom'];
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
    Log::append($e->getMessage());
    echo Log::get();
}
