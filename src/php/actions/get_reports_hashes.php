<?php

use KeepersTeam\Webtlo\Config\Validate as ConfigValidate;

try {
    include_once dirname(__FILE__) . '/../common.php';
    include_once dirname(__FILE__) . '/../classes/api.php';
    include_once dirname(__FILE__) . '/../classes/reports.php';

    // идентификатор подраздела
    if (isset($_POST['forum_id'])) {
        $forum_id = (int) $_POST['forum_id'];
    }

    if (
        !is_int($forum_id)
        || $forum_id < 0
    ) {
        throw new Exception("Error: Неправильный идентификатор подраздела ($forum_id)");
    }

    // Получение настроек.
    $cfg = get_settings();

    // Проверка настроек.
    $user = ConfigValidate::checkUser($cfg);
    if (empty($cfg['subsections'])) {
        throw new Exception('Error: Не выбраны хранимые подразделы');
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
        throw new Exception("Error: Не получены данные о хранимом подразделе № $forum_id");
    }

    // ищем тему со списками
    $topic_id = $reports->search_topic_id($forum[$forum_id]['name']);

    Log::append("Сканирование списков...");

    // подключаемся к api
    if (!isset($api)) {
        $api = new Api($cfg['api_address'], $cfg['api_key']);
        // применяем таймауты
        $api->setUserConnectionOptions($cfg['curl_setopt']['api']);
        Log::append('Получение данных о пирах...');
    }

    $output = [];
    if (empty($topic_id)) {
        Log::append("Error: Не удалось найти тему со списком для подраздела № $forum_id");
    } else {
        // сканируем имеющиеся списки
        $keepers = $reports->scanning_viewtopic($topic_id);
        if ($keepers !== false) {
            // разбираем инфу, полученную из списков
            foreach ($keepers as $index => $keeper) {
                // array( 'post_id' => 4444444, 'nickname' => 'user', 'topics_ids' => array( 0,1,2 ) )
                if (strcasecmp($cfg['tracker_login'], $keeper['nickname']) != 0) {
                    continue;
                }
                if (empty($keeper['topics_ids'])) {
                    continue;
                }
                foreach ($keeper['topics_ids'] as $index => $keeperTopicsIDs) {
                    $keeperTopicsHashes = $api->getTorHash($keeperTopicsIDs);
                    $output = array_merge($output, $keeperTopicsHashes);
                }
            }
            unset($keepers);
        }
    }

    echo json_encode([
        'hashes' => $output,
        'log' => Log::get()
    ]);
} catch (Exception $e) {
    Log::append($e->getMessage());
    echo json_encode([
        'log' => Log::get(),
        'hashes' => "<br /><div>Нет или недостаточно данных для отображения.<br />Проверьте настройки и выполните обновление сведений.</div><br />"
    ]);
}
