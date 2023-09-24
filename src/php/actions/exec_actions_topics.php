<?php

try {
    $result = '';
    include_once dirname(__FILE__) . '/../common.php';
    include_once dirname(__FILE__) . '/../classes/clients.php';
    // разбираем данные
    $actionType = isset($_POST['action']) ? $_POST['action'] : '';
    $labelName = isset($_POST['label']) ? $_POST['label'] : '';
    $removeFiles = isset($_POST['remove_data']) ? $_POST['remove_data'] : '';
    $forceStart = isset($_POST['force_start']) ? $_POST['force_start'] : '';
    // поддерживаемые действия
    $supportedActions = [
        'set_label',
        'start',
        'stop',
        'remove',
    ];
    if (!in_array($actionType, $supportedActions)) {
        $result = 'Попытка выполнить неизвестное действие';
        throw new Exception();
    }
    if (empty($_POST['topic_hashes'])) {
        $result = 'Выберите раздачи';
        throw new Exception();
    }
    if (empty($_POST['tor_clients'])) {
        $result = 'В настройках не найдены торрент-клиенты';
        throw new Exception();
    }
    // получение настроек
    $cfg = get_settings();
    $torrentClients = $_POST['tor_clients'];
    Log::append('Начато выполнение действия "' . $actionType . '" для выбранных раздач...');
    Log::append('Получение хэшей раздач с привязкой к торрент-клиенту...');
    parse_str($_POST['topic_hashes'], $topicHashes);
    $topicHashes = array_chunk($topicHashes['topic_hashes'], 499);
    foreach ($topicHashes as $topicHashes) {
        $placeholders = str_repeat('?,', count($topicHashes) - 1) . '?';
        $data = Db::query_database(
            'SELECT
                client_id,
                info_hash
            FROM Torrents
            WHERE info_hash IN (' . $placeholders . ')',
            $topicHashes,
            true,
            PDO::FETCH_GROUP | PDO::FETCH_COLUMN
        );
        unset($placeholders);
        foreach ($data as $clientID => $clientTorrentHashes) {
            if (isset($torrentHashesByClient[$clientID])) {
                $torrentHashesByClient[$clientID] = array_merge(
                    $torrentHashesByClient[$clientID],
                    $clientTorrentHashes
                );
            } else {
                $torrentHashesByClient[$clientID] = $clientTorrentHashes;
            }
        }
        unset($data);
    }
    unset($topicHashes);
    if (empty($torrentHashesByClient)) {
        $result = 'Не получены данные о выбранных раздачах';
        throw new Exception();
    }
    Log::append('Количество затрагиваемых торрент-клиентов: ' . count($torrentHashesByClient));
    foreach ($torrentHashesByClient as $clientID => $torrentHashes) {
        if (empty($torrentHashes)) {
            continue;
        }
        if (empty($torrentClients[$clientID])) {
            Log::append('В настройках нет данных о торрент-клиенте с идентификатором "' . $clientID . '"');
            continue;
        }
        // данные текущего торрент-клиента
        $torrentClient = $torrentClients[$clientID];
        /**
         * @var utorrent|transmission|vuze|deluge|rtorrent|qbittorrent|flood $client
         */
        $client = new $torrentClient['type'](
            $torrentClient['ssl'],
            $torrentClient['hostname'],
            $torrentClient['port'],
            $torrentClient['login'],
            $torrentClient['password']
        );
        // проверка доступности торрент-клиента
        if (!$client->isOnline()) {
            Log::append('Error: торрент-клиент "' . $torrentClient['comment'] . '" в данный момент недоступен');
            continue;
        }
        // применяем таймауты
        $client->setUserConnectionOptions($cfg['curl_setopt']['torrent_client']);
        switch ($actionType) {
            case 'set_label':
                $response = $client->setLabel($torrentHashes, $labelName);
                break;
            case 'stop':
                $response = $client->stopTorrents($torrentHashes);
                break;
            case 'start':
                $response = $client->startTorrents($torrentHashes, $forceStart);
                break;
            case 'remove':
                $response = $client->removeTorrents($torrentHashes, $removeFiles);
                if ($response !== false) {
                    // помечаем в базе удаление
                    $torrentHashesRemoving = array_chunk($torrentHashes, 500);
                    foreach ($torrentHashesRemoving as $torrentHashesRemoving) {
                        $placeholders = str_repeat('?,', count($torrentHashesRemoving)) . '?';
                        Db::query_database(
                            'DELETE FROM Torrents WHERE info_hash IN (' . $placeholders . ')',
                            $torrentHashesRemoving
                        );
                        unset($placeholders);
                    }
                    unset($torrentHashesRemoving);
                }
                break;
        }
        if ($response === false) {
            Log::append('Error: Возникли проблемы при отправке запроса "' . $actionType . '" для торрент-клиента "' . $torrentClient['comment'] . '"');
        } else {
            Log::append('Действие "' . $actionType . '" для торрент-клиента "' . $torrentClient['comment'] . '" выполнено (' . count($torrentHashes) . ')');
        }
        unset($torrentClient);
    }
    $result = 'Действие "' . $actionType . '" выполнено. За подробностями обратитесь к журналу';
    Log::append('Выполнение действия "' . $actionType . '" завершено');
    echo json_encode(
        [
            'log' => Log::get(),
            'result' => $result,
        ]
    );
} catch (Exception $e) {
    Log::append($e->getMessage());
    echo json_encode(
        [
            'log' => Log::get(),
            'result' => $result,
        ]
    );
}
