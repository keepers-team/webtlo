<?php

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/clients.php';
include_once dirname(__FILE__) . '/../classes/api.php';

// получение настроек
if (!isset($cfg)) {
    $cfg = get_settings();
}
// создаём временные таблицы
Db::query_database(
    'CREATE TEMP TABLE ClientsNew AS
        SELECT hs,cl,dl FROM Clients WHERE 0 = 1'
);
Db::query_database(
    'CREATE TEMP TABLE TopicsUntrackedNew AS
        SELECT id,ss,na,hs,se,si,st,rg FROM TopicsUntracked WHERE 0 = 1'
);
if (!empty($cfg['clients'])) {
    Log::append('Сканирование торрент-клиентов...');
    Log::append('Количество торрент-клиентов: ' . count($cfg['clients']));
    foreach ($cfg['clients'] as $torrentClientID => $torrentClientData) {
        /**
         * @var utorrent|transmission|vuze|deluge|ktorrent|rtorrent|qbittorrent $client
         */
        $client = new $torrentClientData['cl'](
            $torrentClientData['ssl'],
            $torrentClientData['ht'],
            $torrentClientData['pt'],
            $torrentClientData['lg'],
            $torrentClientData['pw']
        );
        // количество раздач
        $numberTorrents = 0;
        if ($client->isOnline()) {
            // применяем таймауты
            $client->setUserConnectionOptions($cfg['curl_setopt']['torrent_client']);
            // получаем список торрентов
            $torrents = $client->getTorrents();
            if ($torrents === false) {
                Log::append('Error: Не удалось получить данные о раздачах от торрент-клиента "' . $torrentClientData['cm'] . '"');
                continue;
            }
            $insertedTorrents = array();
            $numberTorrents = count($torrents);
            // array( 'hash' => 'tor_status' )
            // tor_status: 0 - загружается, 1 - раздаётся, -1 - на паузе или стопе, -2 - с ошибкой
            foreach ($torrents as $torrentHash => $torrentStatus) {
                $insertedTorrents[] = array(
                    'id' => $torrentHash,
                    'cl' => $torrentClientID,
                    'dl' => $torrentStatus,
                );
            }
            unset($torrents);
            $insertedTorrents = array_chunk($insertedTorrents, 500);
            foreach ($insertedTorrents as $insertedTorrents) {
                $select = Db::combine_set($insertedTorrents);
                Db::query_database('INSERT INTO temp.ClientsNew (hs,cl,dl) ' . $select);
                unset($select);
            }
            unset($insertedTorrents);
        }
        Log::append($torrentClientData['cm'] . ' (' . $torrentClientData['cl'] . ') получено раздач: ' . $numberTorrents . '  шт.');
    }
    $numberTorrentClients = Db::query_database(
        'SELECT COUNT() FROM temp.ClientsNew',
        array(),
        true,
        PDO::FETCH_COLUMN
    );
    if ($numberTorrentClients[0] > 0) {
        Db::query_database(
            'INSERT INTO Clients (hs,cl,dl)
            SELECT * FROM temp.ClientsNew'
        );
    }
    if (isset($cfg['subsections'])) {
        $forumsIDs = array_keys($cfg['subsections']);
        $placeholders = str_repeat('?,', count($forumsIDs) - 1) . '?';
    } else {
        $forumsIDs = array();
        $placeholders = '';
    }
    $untrackedTorrentHashesWithClients = Db::query_database(
        'SELECT t.cl, t.hs FROM temp.ClientsNew AS t
        LEFT JOIN Topics ON Topics.hs = t.hs
        WHERE Topics.id IS NULL OR ss NOT IN (' . $placeholders . ')',
        $forumsIDs,
        true,
        PDO::FETCH_ASSOC
    );
    if (!empty($untrackedTorrentHashesWithClients)) {
        Log::append('Найдено сторонних раздач: ' . count($untrackedTorrentHashesWithClients) . ' шт.');
        foreach ($untrackedTorrentHashesWithClients as $key => $value) {
            $untrackedTorrentHashesByClient[$value['cl']][] = $value['hs'];
            $untrackedTorrentHashes[] = $value['hs'];
        }

        foreach ($untrackedTorrentHashesByClient as $clientID => $hashes) {
            $client = new $cfg['clients'][$clientID]['cl'](
                $cfg['clients'][$clientID]['ssl'],
                $cfg['clients'][$clientID]['ht'],
                $cfg['clients'][$clientID]['pt'],
                $cfg['clients'][$clientID]['lg'],
                $cfg['clients'][$clientID]['pw']
            );
            if ($client->isOnline()) {
                $untrackedTorrents = $client->getTorrentsNames($hashes);
            } else {
                continue;
            }
            Log::append('Найдено сторонних раздач в клиенте "' . $cfg['clients'][$clientID]['cl'] . '": ' . count($hashes) . ' шт.');
            Log::append('Перечень найденных раздач:');
            foreach ($untrackedTorrents as $torrentHash => $torrentName) {
                Log::append('[' . $torrentHash . '] - ' . $torrentName);
            }
        }

        // подключаемся к api
        if (!isset($api)) {
            $api = new Api($cfg['api_address'], $cfg['api_key']);
            // применяем таймауты
            $api->setUserConnectionOptions($cfg['curl_setopt']['api']);
        }
        $untrackedTopics = $api->getTorrentTopicData($untrackedTorrentHashes, 'hash');
        unset($untrackedTorrentHashes);
        if (!empty($untrackedTopics)) {
            foreach ($untrackedTopics as $topicID => $topicData) {
                if (empty($topicData)) {
                    continue;
                }
                $insertedUntrackedTopics[] = array(
                    'id' => $topicID,
                    'ss' => $topicData['forum_id'],
                    'na' => $topicData['topic_title'],
                    'hs' => $topicData['info_hash'],
                    'se' => $topicData['seeders'],
                    'si' => $topicData['size'],
                    'st' => $topicData['tor_status'],
                    'rg' => $topicData['reg_time'],
                );
            }
            unset($untrackedTopics);
            $insertedUntrackedTopics = array_chunk($insertedUntrackedTopics, 500);
            foreach ($insertedUntrackedTopics as $insertedUntrackedTopics) {
                $select = Db::combine_set($insertedUntrackedTopics);
                unset($insertedUntrackedTopics);
                Db::query_database('INSERT INTO temp.TopicsUntrackedNew ' . $select);
                unset($select);
            }
            unset($insertedUntrackedTopics);
            $numberUntrackedTopics = Db::query_database(
                'SELECT COUNT() FROM temp.TopicsUntrackedNew',
                array(),
                true,
                PDO::FETCH_COLUMN
            );
            if ($numberUntrackedTopics[0] > 0) {
                Db::query_database(
                    'INSERT INTO TopicsUntracked (id,ss,na,hs,se,si,st,rg)
                    SELECT * FROM temp.TopicsUntrackedNew'
                );
            }
        }
    }
    Db::query_database(
        'DELETE FROM TopicsUntracked
        WHERE id NOT IN (
            SELECT id FROM temp.TopicsUntrackedNew
        )'
    );
}
Db::query_database(
    'DELETE FROM Clients WHERE hs || cl NOT IN (
        SELECT Clients.hs || Clients.cl FROM temp.ClientsNew LEFT JOIN Clients
        ON temp.ClientsNew.hs = Clients.hs AND temp.ClientsNew.cl = Clients.cl
        WHERE Clients.hs IS NOT NULL
    ) OR cl NOT IN (
        SELECT DISTINCT cl FROM temp.ClientsNew
    )'
);
