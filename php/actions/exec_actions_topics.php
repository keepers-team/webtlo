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
    $supportedActions = array(
        'set_label',
        'start',
        'stop',
        'remove',
    );
    if (!in_array($actionType, $supportedActions)) {
        $result = 'Попытка выполнить неизвестное действие';
        throw new Exception();
    }
    if (empty($_POST['topics_ids'])) {
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
    parse_str($_POST['topics_ids'], $topicsIDs);
    $topicsIDs = array_chunk($topicsIDs['topics_ids'], 499);
    foreach ($topicsIDs as $topicsIDs) {
        $placeholders = str_repeat('?,', count($topicsIDs) - 1) . '?';
        $torrentHashesClients = Db::query_database(
            'SELECT cl,hs FROM Clients
            WHERE hs IN (
                SELECT hs FROM (
                    SELECT id,hs FROM Topics
                    UNION
                    SELECT id,hs FROM TopicsUntracked
                ) WHERE id IN (' . $placeholders . ')
            )',
            $topicsIDs,
            true,
            PDO::FETCH_GROUP | PDO::FETCH_COLUMN
        );
        foreach ($torrentHashesClients as $torrentClientID => $torrentHashesClient) {
            if (isset($torrentHashes[$torrentClientID])) {
                $torrentHashes[$torrentClientID] = array_merge(
                    $torrentHashes[$torrentClientID],
                    $torrentHashesClient
                );
            } else {
                $torrentHashes[$torrentClientID] = $torrentHashesClient;
            }
        }
        unset($torrentHashesClients);
        unset($placeholders);
    }
    unset($topicsIDs);
    if (empty($torrentHashes)) {
        $result = 'Не получены данные о выбранных раздачах';
        throw new Exception();
    }
    Log::append('Количество затрагиваемых торрент-клиентов: ' . count($torrentHashes));
    foreach ($torrentHashes as $torrentClientID => $torrentHashes) {
        if (empty($torrentHashes)) {
            continue;
        }
        if (empty($torrentClients[$torrentClientID])) {
            Log::append('В настройках нет данных о торрент-клиенте с идентификатором "' . $torrentClientID . '"');
            continue;
        }
        // данные текущего торрент-клиента
        $torrentClient = $torrentClients[$torrentClientID];
        /**
         * @var utorrent|transmission|vuze|deluge|ktorrent|rtorrent|qbittorrent $client
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
                            'DELETE FROM Clients WHERE hs IN (' . $placeholders . ')',
                            $torrentHashesRemoving
                        );
                        unset($placeholders);
                    }
                    unset($torrentHashesRemoving);
                    break;
                }
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
        array(
            'log' => Log::get(),
            'result' => $result,
        )
    );
} catch (Exception $e) {
    Log::append($e->getMessage());
    echo json_encode(
        array(
            'log' => Log::get(),
            'result' => $result,
        )
    );
}
