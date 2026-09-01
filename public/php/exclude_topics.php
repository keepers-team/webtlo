<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Storage\Table\TopicsExcluded;

try {
    $request = json_decode((string) file_get_contents('php://input'), true);

    // Список добавляемых раздач (info_hash).
    if (empty($request['topic_hashes']) || !is_array($request['topic_hashes'])) {
        throw new RuntimeException('Выберите раздачи, которые желаете исключить');
    }

    $app = App::create();
    $db  = $app->getDataBase();

    $topicsExcluded = $app->get(TopicsExcluded::class);

    $topicHashes = Helper::convertKeysToString(array: $request['topic_hashes']);

    /**
     * Признак исключения раздач:
     * 1 - добавить в список исключений.
     * 0 - удалить из списка исключений.
     */
    $exclude = !empty($request['exclude']);

    $topicsExcluded->manageTopics(hashes: $topicHashes, exclude: $exclude);

    $result = 'Обновление "чёрного списка" раздач успешно завершено';
} catch (Exception $e) {
    $result = $e->getMessage();
}

echo App::decorateJsonResponse(result: $result);
