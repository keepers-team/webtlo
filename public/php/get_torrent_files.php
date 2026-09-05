<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\Action\TopicDownload;
use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Helper;

// Подключаем контейнер.
$app = App::create();
$log = $app->getLogger();

try {
    $result = '';

    $request = json_decode((string) file_get_contents('php://input'), true);

    // Список добавляемых раздач (info_hash).
    if (empty($request['topic_hashes']) || !is_array($request['topic_hashes'])) {
        throw new RuntimeException('Выберите раздачи для скачивания.');
    }

    // Идентификатор подраздела/разворота.
    $listingId = $request['forum_id'] ?? 0;

    // Нужна ли замена PASSKEY.
    $replace_passkey = (bool) ($request['replace_passkey'] ?? false);

    $topicHashes = Helper::convertKeysToString(array: $request['topic_hashes']);

    $downloader = $app->get(TopicDownload::class);

    $result = $downloader->process(
        listingId     : $listingId,
        hashes        : $topicHashes,
        replacePasskey: $replace_passkey,
    );
} catch (Exception $e) {
    $result = $e->getMessage();
    $log->error($result);
} finally {
    $log->info('-- DONE --');
}

echo App::decorateJsonResponse(result: $result);
