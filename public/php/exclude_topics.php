<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Storage\Table\TopicsExcluded;

try {
    if (empty($_POST['topic_hashes'])) {
        throw new Exception('Выберите раздачи, которые желаете исключить');
    }

    $app = App::create();
    $db  = $app->getDataBase();

    /** @var TopicsExcluded $topicsExcluded */
    $topicsExcluded = $app->get(TopicsExcluded::class);

    parse_str($_POST['topic_hashes'], $topicHashes);
    $topicHashes = Helper::convertKeysToString((array) $topicHashes['topic_hashes']);

    /**
     * Признак исключения раздач:
     * 1 - добавить в список исключений.
     * 0 - удалить из списка исключений.
     */
    $exclude = !empty($_POST['exclude']);

    $topicsExcluded->manageTopics(hashes: $topicHashes, exclude: $exclude);

    $result = 'Обновление "чёрного списка" раздач успешно завершено';
} catch (Exception $e) {
    $result = $e->getMessage();
}

echo App::decorateJsonResponse($result);
