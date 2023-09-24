<?php

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/api.php';
include_once dirname(__FILE__) . '/../classes/clients.php';

Timers::start('control');
Log::append('Info: Начат процесс регулировки раздач в торрент-клиентах...');

// получение настроек
$cfg = get_settings();
// проверка настроек
if (empty($cfg['clients'])) {
    throw new Exception('Error: Не удалось получить список торрент-клиентов');
}
if (empty($cfg['subsections'])) {
    throw new Exception('Error: Не выбраны хранимые подразделы');
}

if (isset($checkEnabledCronAction)) {
    $checkEnabledCronAction = $cfg['automation'][$checkEnabledCronAction] ?? -1;
    if ($checkEnabledCronAction == 0) {
        throw new Exception('Notice: Автоматическая регулировка раздач отключена в настройках.');
    }
}


$forumsIDs = array_keys($cfg['subsections']);
$placeholdersForumsIDs = str_repeat('?,', count($forumsIDs) - 1) . '?';

foreach ($cfg['clients'] as $torrentClientID => $torrentClientData) {
    $clientTag = sprintf('%s (%s)', $torrentClientData['cm'], $torrentClientData['cl']);

    $clientControlPeers = ($torrentClientData['control_peers'] !== "") ? (int)$torrentClientData['control_peers'] : -2;
    if ($clientControlPeers == -1) {
        Log::append("Notice: Для клиента $clientTag отключена регулировка");
        continue;
    }

    Timers::start("control_client_$torrentClientID");
    /**
     * * @var utorrent|transmission|vuze|deluge|rtorrent|qbittorrent|flood $client
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
        Log::append("Notice: Клиент $clientTag в данный момент недоступен");
        continue;
    }
    // применяем таймауты
    $client->setUserConnectionOptions($cfg['curl_setopt']['torrent_client']);

    // получение данных от торрент-клиента
    Log::append("Info: Получаем раздачи торрент-клиента $clientTag");
    Timers::start("get_client_$torrentClientID");
    $torrents = $client->getAllTorrents(['simple' => true]);
    if ($torrents === false) {
        Log::append("Error: Не удалось получить данные о раздачах от торрент-клиента $clientTag");
        continue;
    }
    Log::append(sprintf(
        '%s получено раздач: %d шт за %s',
        $clientTag,
        count($torrents),
        Timers::getExecTime("get_client_$torrentClientID")
    ));

    Timers::start("get_topics_$torrentClientID");
    // ограничение на количество хэшей за раз
    $placeholdersLimit = 999;
    $torrentsHashes = array_chunk(
        array_keys($torrents),
        $placeholdersLimit - count($forumsIDs)
    );
    $topicsHashes = [];
    $unaddedHashes = array_keys($torrents);
    // вытаскиваем из базы хэши раздач только для хранимых подразделов
    foreach ($torrentsHashes as $torrentsHashes) {
        $placeholdersTorrentsHashes = str_repeat('?,', count($torrentsHashes) - 1) . '?';
        $responseTopicsHashes = Db::query_database(
            'SELECT
                ss,
                hs
            FROM Topics
            WHERE
                hs IN (' . $placeholdersTorrentsHashes . ')
                AND ss IN (' . $placeholdersForumsIDs . ')',
            array_merge($torrentsHashes, $forumsIDs),
            true,
            PDO::FETCH_GROUP | PDO::FETCH_COLUMN
        );
        foreach ($responseTopicsHashes as $forumID => $hashes) {
            $unaddedHashes = array_diff($unaddedHashes, $hashes);
            if (isset($topicsHashes[$forumID])) {
                $topicsHashes[$forumID] = array_merge($topicsHashes[$forumID], $hashes);
            } else {
                $topicsHashes[$forumID] = $hashes;
            }
        }
        unset($placeholdersTorrentsHashes);
        unset($responseTopicsHashes);
    }
    Log::append(sprintf(
        'Поиск раздач в БД завершён за %s. Найдено раздач из хранимых подразделов %d шт, из прочих %d шт.',
        Timers::getExecTime("get_topics_$torrentClientID"),
        count($topicsHashes, COUNT_RECURSIVE) - count($topicsHashes),
        count($unaddedHashes)
    ));

    asort($topicsHashes);
    if (count($unaddedHashes)) {
        $topicsHashes["unadded"] = $unaddedHashes;
    }
    unset($torrentsHashes, $unaddedHashes);

    if (!empty($topicsHashes)) {
        // подключаемся к api
        if (!isset($api)) {
            $api = new Api($cfg['api_address'], $cfg['api_key']);
            // применяем таймауты
            $api->setUserConnectionOptions($cfg['curl_setopt']['api']);
            Log::append('Получение данных о пирах...');
        }

        foreach ($topicsHashes as $forumID => $hashes) {
            // пропустим не хранимые подразделы, если их регулировка отключена
            if (!$cfg['topics_control']['unadded_subsections'] && !isset($cfg['subsections'][$forumID])) {
                continue;
            }
            // пропустим исключённые из регулировки подразделы
            $subControlPeers = $cfg['subsections'][$forumID]['control_peers'] ?? -2;
            $subControlPeers = ($subControlPeers !== "") ? (int)$subControlPeers : -2;
            if ($subControlPeers == -1) {
                Log::append('Для подраздела '. $forumID .' отключена регулировка');
                continue;
            }
            // регулируемое значение пиров
            $controlPeers = get_control_peers($cfg['topics_control']['peers'], $clientControlPeers, $subControlPeers);

            Timers::start("subsection_$forumID");
            // получаем данные о пирах
            $peerStatistics = $api->getPeerStats($hashes, 'hash');

            if ($peerStatistics !== false) {
                foreach ($peerStatistics as $topicHash => $topicData) {
                    if (
                        // пропускаем отсутствующий торрент
                        empty($torrents[$topicHash])
                        // пропускаем торрент с ошибкой
                        || $torrents[$topicHash]['error'] == 1
                        // пропускаем торрент на загрузке
                        || $torrents[$topicHash]['done'] != 1
                    ) {
                        continue;
                    }
                    // статус торрента
                    $torrentStatus = $torrents[$topicHash]['paused'] == 1 ? -1 : 1;
                    // учитываем себя
                    $topicData['seeders'] -= $topicData['seeders'] ? $torrentStatus : 0;
                    // находим значение личей
                    $leechers = $cfg['topics_control']['leechers'] ? $topicData['leechers'] : 0;
                    // количество сидов-хранителей раздачи, которых нужно вычесть из счётчика
                    $keepers = 0;
                    if ($cfg['topics_control']['keepers'] > 0) {
                        // хранители раздачи, исключая себя
                        $keepers = count(array_diff($topicData['keepers'], [$cfg['user_id']]));
                        $keepers = min($keepers, (int)$cfg['topics_control']['keepers']);
                    }
                    // находим значение пиров
                    $peers = $topicData['seeders'] + $leechers - $keepers;
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

            Log::append(sprintf(
                'Обработка раздач раздела %s (%d шт) завершена за %s, лимит сидов %d',
                $forumID,
                count($hashes),
                Timers::getExecTime("subsection_$forumID"),
                $controlPeers
            ));
            unset($forumID, $hashes, $peerStatistics);
        }
    }
    if (empty($controlTopics)) {
        Log::append("Notice: Регулировка раздач не требуется для торрент-клиента $clientTag");
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
        Log::append("Запрос на запуск раздач торрент-клиенту $clientTag отправлен ($totalStartedTopics шт)");
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
        Log::append("Запрос на остановку раздач торрент-клиенту $clientTag отправлен ($totalStoppedTopics шт)");
    }
    unset($controlTopics);

    Log::append(sprintf(
        'Info: Регулировка раздач в торрент-клиенте %s завершена за %s',
        $clientTag,
        Timers::getExecTime("control_client_$torrentClientID")
    ));
}
Log::append('Info: Регулировка раздач в торрент-клиентах завершена за ' . Timers::getExecTime('control'));


// Определяем лимит для регулировки раздач
function get_control_peers(int $controlPeers, int $clientControlPeers, int $subControlPeers): int
{
    // Задан лимит для клиента и для раздела
    if ($clientControlPeers > -1 && $subControlPeers > -1) {
        // Если лимит на клиент меньше лимита на раздел, то используем клиент
        $controlPeers = $subControlPeers;
        if ($clientControlPeers < $subControlPeers) {
            $controlPeers = $clientControlPeers;
        }
    }
    // Задан лимит только для клиента
    elseif ($clientControlPeers > -1) {
        $controlPeers = $clientControlPeers;
    }
    // Задан лимит только для раздела
    elseif ($subControlPeers > -1) {
        $controlPeers = $subControlPeers;
    }

    return max($controlPeers, 0);
}
