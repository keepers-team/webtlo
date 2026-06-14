<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\Action\ClientAddTopics;
use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Helper;

// Подключаем контейнер.
$app = App::create();
$log = $app->getLogger();

try {
    // Список добавляемых раздач (info_hash).
    if (empty($_POST['topic_hashes'])) {
        throw new RuntimeException('Выберите раздачи');
    }

    parse_str($_POST['topic_hashes'], $topicHashes);
    $topicHashes = Helper::convertKeysToString((array) $topicHashes['topic_hashes']);

    /** @var ClientAddTopics $addTopics */
    $addTopics = $app->get(ClientAddTopics::class);

    $addTopics->process($topicHashes);

    $result = 'Добавление завершено.';
} catch (RuntimeException $e) {
    $result = $e->getMessage();
    $log->error($result);
} finally {
    $log->info('-- DONE --');
}

echo App::decorateJsonResponse($result);
