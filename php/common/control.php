<?php

$starttime = microtime(true);

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/api.php';
include_once dirname(__FILE__) . '/../classes/clients.php';

Log::append('Начат процесс регулировки раздач в торрент-клиентах...');
// получение настроек
$cfg = get_settings();
// проверка настроек
if (empty($cfg['clients'])) {
    throw new Exception('Error: Не удалось получить список торрент-клиентов');
}
if (empty($cfg['subsections'])) {
    throw new Exception('Error: Не выбраны хранимые подразделы');
}
$forumsIDs = array_keys($cfg['subsections']);
$placeholdersForumsIDs = str_repeat('?,', count($forumsIDs) - 1) . '?';
foreach ($cfg['clients'] as $torrentClientID => $torrentClientData) {
    /**
     * * @var utorrent|transmission|vuze|deluge|ktorrent|rtorrent|qbittorrent $client
     * */
    $client = new $torrentClientData['cl'](
        $torrentClientData['ssl'],
        $torrentClientData['ht'],
        $torrentClientData['pt'],
        $torrentClientData['lg'],
        $torrentClientData['pw']
    );
    // проверка доступности торрент-клиента
    if ($client->isOnline() === false) {
        continue;
    }
    // применяем таймауты
    $client->setUserConnectionOptions($cfg['curl_setopt']['torrent_client']);
    // получение данных от торрент-клиента
    $torrents = $client->getTorrents();
    if ($torrents === false) {
        Log::append('Error: Не удалось получить данные о раздачах от торрент-клиента "' . $torrentClientData['cm'] . '"');
        continue;
    }
    // ограничение на количество хэшей за раз
    $placeholdersLimit = 999;
    $torrentsHashes = array_chunk(
        array_keys($torrents),
        $placeholdersLimit - count($forumsIDs)
    );
    $topicsHashes = array();
    // вытаскиваем из базы хэши раздач только для хранимых подразделов
    foreach ($torrentsHashes as $torrentsHashes) {
        $placeholdersTorrentsHashes = str_repeat('?,', count($torrentsHashes) - 1) . '?';
        $responseTopicsHashes = Db::query_database(
            'SELECT ss,hs FROM Topics WHERE hs IN (' . $placeholdersTorrentsHashes . ') AND ss IN (' . $placeholdersForumsIDs . ')',
            array_merge($torrentsHashes, $forumsIDs),
            true,
            PDO::FETCH_GROUP | PDO::FETCH_COLUMN
        );
        foreach ($responseTopicsHashes as $forumID => $hashes) {
            if (isset($topicsHashes[$forumID])) {
                $topicsHashes[$forumID] = array_merge($topicsHashes[$forumID], $hashes);
            } else {
                $topicsHashes[$forumID] = $hashes;
            }
        }
        unset($placeholdersTorrentsHashes);
        unset($responseTopicsHashes);
    }
    unset($torrentsHashes);
    if (!empty($topicsHashes)) {
        // подключаемся к api
        if (!isset($api)) {
            $api = new Api($cfg['api_address'], $cfg['api_key']);
            // применяем таймауты
            $api->setUserConnectionOptions($cfg['curl_setopt']['api']);
            Log::append('Получение данных о пирах...');
        }
        foreach ($topicsHashes as $forumID => $hashes) {
            $controlPeersForum = $cfg['subsections'][$forumID]['control_peers'];
            // пропустим исключённые из регулировки подразделы
            if ($controlPeersForum == -1) {
                continue;
            }
            // получаем данные о пирах
            $peerStatistics = $api->getPeerStats($hashes, 'hash');
            unset($topicsHashhasheses);
            if ($peerStatistics !== false) {
                foreach ($peerStatistics as $topicHash => $topicData) {
                    // если нет такой раздачи или идёт загрузка раздачи, идём дальше
                    if (empty($torrents[$topicHash])) {
                        continue;
                    }
                    // статус раздачи
                    $torrentStatus = $torrents[$topicHash];
                    // учитываем себя
                    $topicData['seeders'] -= $topicData['seeders'] ? $torrentStatus : 0;
                    // находим значение личей
                    $leechers = $cfg['topics_control']['leechers'] ? $topicData['leechers'] : 0;
                    // находим значение пиров
                    $peers = $topicData['seeders'] + $leechers;
                    // регулируемое значение пиров
                    $controlPeers = $controlPeersForum == '' ? $cfg['topics_control']['peers'] : $controlPeersForum;
                    // учитываем вновь прибывшего "лишнего" сида
                    if (
                        $topicData['seeders']
                        && $peers == $controlPeers
                        && $torrentStatus == 1
                    ) {
                        $peers++;
                    }
                    // стопим только, если есть сиды
                    $peersState = $peers > $controlPeers
                        || !$cfg['topics_control']['no_leechers']
                        && !$topicData['leechers'];
                    if (
                        $topicData['seeders']
                        && $peersState
                    ) {
                        if ($torrentStatus == 1) {
                            $controlTopics['stop'][] = $topicHash;
                        }
                    } else {
                        if ($torrentStatus == -1) {
                            $controlTopics['start'][] = $topicHash;
                        }
                    }
                }
            }
            unset($peerStatistics);
        }
    }
    if (empty($controlTopics)) {
        Log::append('Notice: Регулировка раздач не требуется для торрент-клиента "' . $torrentClientData['cm'] . '"');
        continue;
    }
    // запускаем
    if (!empty($controlTopics['start'])) {
        $totalStartedTopics = count($controlTopics['start']);
        $controlTopics['start'] = array_chunk($controlTopics['start'], 100);
        foreach ($controlTopics['start'] as $hashes) {
            $response = $client->startTorrents($hashes);
            if ($response === false) {
                Log::append('Error: Возникли проблемы при отправке запроса на запуск раздач');
            }
        }
        Log::append('Запрос на запуск раздач торрент-клиенту "' . $torrentClientData['cm'] . '" отправлен (' . $totalStartedTopics . ')');
    }
    // останавливаем
    if (!empty($controlTopics['stop'])) {
        $totalStoppedTopics = count($controlTopics['stop']);
        $controlTopics['stop'] = array_chunk($controlTopics['stop'], 100);
        foreach ($controlTopics['stop'] as $hashes) {
            $response = $client->stopTorrents($hashes);
            if ($response === false) {
                Log::append('Error: Возникли проблемы при отправке запроса на остановку раздач');
            }
        }
        Log::append('Запрос на остановку раздач торрент-клиенту "' . $torrentClientData['cm'] . '" отправлен (' . $totalStoppedTopics . ')');
    }
    unset($controlTopics);
}
$endtime = microtime(true);
Log::append('Регулировка раздач в торрент-клиентах завершена за ' . convert_seconds($endtime - $starttime));
