<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Helper;

try {
    if (empty($_POST['topic_hashes'])) {
        $result = 'Выберите раздачи';

        throw new Exception();
    }

    $app = App::create();
    $db  = $app->getDataBase();

    parse_str($_POST['topic_hashes'], $topicHashes);
    $topicHashes = Helper::convertKeysToString((array) $topicHashes['topic_hashes']);

    $value = empty($_POST['value']) ? 0 : 1;

    $topicHashes = array_chunk($topicHashes, 500);

    foreach ($topicHashes as $topicHashesChunk) {
        if ($value == 0) {
            $in = str_repeat('?,', count($topicHashesChunk) - 1) . '?';
            $db->executeStatement(
                "DELETE FROM TopicsExcluded WHERE info_hash IN ($in)",
                $topicHashesChunk
            );
        } elseif ($value == 1) {
            $select = str_repeat('SELECT ? UNION ALL ', count($topicHashesChunk) - 1) . ' SELECT ?';
            $db->executeStatement(
                "INSERT INTO TopicsExcluded (info_hash) $select",
                $topicHashesChunk
            );
        }

        unset($topicHashesChunk);
    }

    echo 'Обновление "чёрного списка" раздач успешно завершено';
} catch (Exception $e) {
    echo $result ?? 'Error occurred';
}
