<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\Action\ClientAddTopics;
use KeepersTeam\Webtlo\Action\ClientAddUnregisteredTopics;
use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Helper;

// Подключаем контейнер.
$app = App::create();
$log = $app->getLogger();

try {
    $request = json_decode((string) file_get_contents('php://input'), true);

    if (isset($request['unregistered_topics'])) {
        if (!is_array($request['unregistered_topics']) || $request['unregistered_topics'] === []) {
            throw new RuntimeException('Выберите раздачи для скачивания.');
        }

        $result = $app->get(ClientAddUnregisteredTopics::class)->process($request['unregistered_topics']);
    } else {
        // Список добавляемых раздач (info_hash).
        if (empty($request['topic_hashes']) || !is_array($request['topic_hashes'])) {
            throw new RuntimeException('Выберите раздачи для скачивания.');
        }

        $topicHashes = Helper::convertKeysToString(array: $request['topic_hashes']);
        $result      = $app->get(ClientAddTopics::class)->process(hashes: $topicHashes);
    }
} catch (RuntimeException $e) {
    $result = $e->getMessage();
    $log->error($result);
} finally {
    $log->info('-- DONE --');
}

echo App::decorateJsonResponse(result: $result);
