<?php

try {
    include_once dirname(__FILE__) . '/../common.php';

    if (empty($_POST['topic_hashes'])) {
        $result = "Выберите раздачи";
        throw new Exception();
    }

    parse_str($_POST['topic_hashes'], $topicHashes);

    $value = empty($_POST['value']) ? 0 : 1;

    $topicHashes = array_chunk($topicHashes['topic_hashes'], 500);

    foreach ($topicHashes as $topicHashes) {
        if ($value == 0) {
            $in = str_repeat('?,', count($topicHashes) - 1) . '?';
            Db::query_database(
                "DELETE FROM TopicsExcluded WHERE info_hash IN ($in)",
                $topicHashes
            );
        } elseif ($value == 1) {
            $select = str_repeat('SELECT ? UNION ALL ', count($topicHashes) - 1) . ' SELECT ?';
            Db::query_database(
                "INSERT INTO TopicsExcluded (info_hash) $select",
                $topicHashes
            );
        }
    }

    echo 'Обновление "чёрного списка" раздач успешно завершено';
} catch (Exception $e) {
    echo $e->getMessage();
}
