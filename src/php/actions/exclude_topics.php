<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Legacy\Db;

try {
    if (empty($_POST['topic_hashes'])) {
        $result = "Выберите раздачи";
        throw new Exception();
    }

    parse_str($_POST['topic_hashes'], $topicHashes);
    $topicHashes = Helper::convertKeysToString((array)$topicHashes['topic_hashes']);

    $value = empty($_POST['value']) ? 0 : 1;

    $topicHashes = array_chunk($topicHashes, 500);

    foreach ($topicHashes as $topicHashesChunk) {
        if ($value == 0) {
            $in = str_repeat('?,', count($topicHashesChunk) - 1) . '?';
            Db::query_database(
                "DELETE FROM TopicsExcluded WHERE info_hash IN ($in)",
                $topicHashesChunk
            );
        } elseif ($value == 1) {
            $select = str_repeat('SELECT ? UNION ALL ', count($topicHashesChunk) - 1) . ' SELECT ?';
            Db::query_database(
                "INSERT INTO TopicsExcluded (info_hash) $select",
                $topicHashesChunk
            );
        }

        unset($topicHashesChunk);
    }

    echo 'Обновление "чёрного списка" раздач успешно завершено';
} catch (Exception $e) {
    echo $e->getMessage();
}
