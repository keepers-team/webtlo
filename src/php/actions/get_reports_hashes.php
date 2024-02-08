<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Config\Validate as ConfigValidate;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\Legacy\Db;
use KeepersTeam\Webtlo\Legacy\Log;

try {
    include_once dirname(__FILE__) . '/../classes/reports.php';

    $app = AppContainer::create();
    // Получение настроек.
    $cfg = $app->getLegacyConfig();

    $logger = $app->getLogger();

    // идентификатор подраздела
    $forum_id = (int)($_POST['forum_id'] ?? -1);
    if ($forum_id < 0) {
        throw new RuntimeException("Error: Неправильный идентификатор подраздела ($forum_id)");
    }

    // Проверка настроек.
    $user = ConfigValidate::checkUser($cfg);

    if (empty($cfg['subsections'])) {
        throw new RuntimeException('Error: Не выбраны хранимые подразделы');
    }

    // подключаемся к форуму
    $reports = new Reports(
        $cfg['forum_address'],
        $user
    );

    // применяем таймауты
    $reports->curl_setopts($cfg['curl_setopt']['forum']);

    // получение данных о подразделе
    $forum = Db::query_database(
        "SELECT * FROM Forums WHERE id = ?",
        [$forum_id],
        true,
        PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE
    );

    if (empty($forum)) {
        throw new RuntimeException("Не получены данные о хранимом подразделе № $forum_id");
    }

    // ищем тему со списками
    $topic_id = $reports->search_topic_id($forum[$forum_id]['name']);

    $logger->info("Сканирование списков...");
    $apiClient = $app->getApiClient();

    if (empty($topic_id)) {
        throw new RuntimeException("Не удалось найти тему со списком для подраздела № $forum_id");
    }

    // Сканируем списки выбранного подраздела.
    $keepers = $reports->scanning_viewtopic($topic_id);
    if ($keepers !== false) {
        $userId = (int)$cfg['user_id'];
        // Получаем ид раздач из своих списков.
        $topics = array_reduce($keepers, function($carry, $post) use ($userId) {
            if ($post['user_id'] === $userId) {
                return array_merge((array)$carry, ...$post['topics_ids']);
            }

            return $carry;
        });
        $topics = array_unique($topics);
        unset($keepers);

        // Ищем хеши раздач из своих списков.
        $response = $apiClient->getTopicsDetails($topics);
        if ($response instanceof ApiError) {
            $logger->error(sprintf('%d %s', $response->code, $response->text));
            throw new RuntimeException('Error: Не получены дополнительные данные о раздачах');
        }

        $output = array_map(fn($tp) => $tp->hash, $response->topics);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    if (isset($logger)) {
        $logger->warning($error);
    }
}

$result = [
    'error'  => $error ?? '',
    'hashes' => $output ?? [],
    'log'    => Log::get(),
];
echo json_encode($result, JSON_UNESCAPED_UNICODE);
