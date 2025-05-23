<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Legacy\Log;

try {
    $app = App::create();
    $log = $app->getLogger();

    // идентификатор подраздела
    $subForumId = (int) ($_POST['forum_id'] ?? -1);
    if ($subForumId < 0) {
        throw new RuntimeException("Error: Неправильный идентификатор подраздела ($subForumId)");
    }

    // Подключаемся к API отчётов.
    $apiReport = $app->getApiReportClient();

    // Запрашиваем список хранимых раздач в заданном подразделе.
    $result = $apiReport->getUserKeptReleases($subForumId);

    if ($result !== null) {
        $columns   = array_flip($result['columns']);
        $hashIndex = $columns['info_hash'];

        // Получаем хеши раздач и возвращаем их.
        $output = array_map(fn($el) => $el[$hashIndex], $result['kept_releases']);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    if (isset($log)) {
        $log->warning($error);
    }
}

$result = [
    'error'  => $error ?? '',
    'hashes' => $output ?? [],
    'log'    => Log::get(),
];
echo json_encode($result, JSON_UNESCAPED_UNICODE);
