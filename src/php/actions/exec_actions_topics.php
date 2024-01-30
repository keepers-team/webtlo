<?php

use KeepersTeam\Webtlo\Legacy\Db;
use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\Module\Torrents;

try {
    include_once dirname(__FILE__) . '/../common.php';
    include_once dirname(__FILE__) . '/../classes/clients.php';

    $result = '';

    // разбираем данные
    $actionType  = $_POST['action'] ?? '';
    $labelName   = $_POST['label'] ?? '';
    $removeFiles = $_POST['remove_data'] ?? '';
    $forceStart  = $_POST['force_start'] ?? '';

    // поддерживаемые действия
    $supportedActions = [
        'set_label',
        'start',
        'stop',
        'remove',
    ];

    if (!in_array($actionType, $supportedActions)) {
        throw new Exception('Попытка выполнить неизвестное действие');
    }
    if (empty($_POST['topic_hashes'])) {
        throw new Exception('Выберите раздачи');
    }
    if (empty($_POST['tor_clients'])) {
        throw new Exception('В настройках не найдены торрент-клиенты');
    }
    // получение настроек
    $cfg = get_settings();

    Log::append(sprintf('Начато выполнение действия "%s" для выбранных раздач...', $actionType));
    Log::append('Получение хэшей раздач с привязкой к торрент-клиенту...');

    $torrentClients = $_POST['tor_clients'];
    parse_str($_POST['topic_hashes'], $topicHashes);

    $topicHashesChunks = array_chunk($topicHashes['topic_hashes'], 499);
    foreach ($topicHashesChunks as $topicHashes) {
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
        throw new Exception('Не получены данные о выбранных раздачах');
    }
    Log::append(sprintf('Количество затрагиваемых торрент-клиентов: %s', count($torrentHashesByClient)));
    foreach ($torrentHashesByClient as $clientID => $torrentHashes) {
        if (empty($torrentHashes)) {
            continue;
        }
        if (empty($torrentClients[$clientID])) {
            Log::append(sprintf('В настройках нет данных о торрент-клиенте с идентификатором "%s"', $clientID));
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
            Log::append(sprintf('Error: торрент-клиент "%s" в данный момент недоступен', $torrentClient['comment']));
            continue;
        }
        // применяем таймауты
        $client->setUserConnectionOptions($cfg['curl_setopt']['torrent_client']);

        $response = false;
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
                    Torrents::removeTorrents($torrentHashes);
                }
                break;
        }
        if ($response === false) {
            Log::append(
                sprintf(
                    'Error: Возникли проблемы при отправке запроса "%s" для торрент-клиента "%s"',
                    $actionType,
                    $torrentClient['comment']
                )
            );
        } else {
            Log::append(
                sprintf(
                    'Действие "%s" для торрент-клиента "%s" выполнено (%s)',
                    $actionType,
                    $torrentClient['comment'],
                    count($torrentHashes)
                )
            );
        }
        unset($torrentClient);
    }
    Log::append(sprintf('Выполнение действия "%s" завершено', $actionType));

    $result = sprintf('Действие "%s" выполнено. За подробностями обратитесь к журналу', $actionType);
} catch (Exception $e) {
    $result = $e->getMessage();
    Log::append($result);
}

echo json_encode([
    'log'    => Log::get(),
    'result' => $result,
]);