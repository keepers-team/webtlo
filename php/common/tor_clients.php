<?php

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/clients.php';
include_once dirname(__FILE__) . '/../classes/api.php';
include_once dirname(__FILE__) . '/../classes/reports.php';

// получение настроек
if (!isset($cfg)) {
    $cfg = get_settings();
}

$torrentsFields = [
    'info_hash',
    'topic_id',
    'client_id',
    'done',
    'error',
    'name',
    'paused',
    'time_added',
    'total_size',
    'tracker_error'
];

$torrentsColumns = implode(',', $torrentsFields);

// создаём временные таблицы
Db::query_database(
    'CREATE TEMP TABLE TorrentsNew AS SELECT ' . $torrentsColumns . ' FROM Torrents WHERE 0 = 1'
);

Db::query_database(
    'CREATE TEMP TABLE TopicsUntrackedNew AS SELECT id,ss,na,hs,se,si,st,rg FROM TopicsUntracked WHERE 0 = 1'
);

Db::query_database(
    'CREATE TEMP TABLE TopicsUnregisteredNew AS
        SELECT
            info_hash,
            name,
            status,
            priority,
            transferred_from,
            transferred_to,
            transferred_by_whom
        FROM TopicsUnregistered
        WHERE 0 = 1'
);

if (empty($cfg['clients'])) {
    Db::query_database('DELETE FROM Torrents');
    return;
}

Log::append('Сканирование торрент-клиентов...');
Log::append('Количество торрент-клиентов: ' . count($cfg['clients']));

foreach ($cfg['clients'] as $torrentClientID => $torrentClientData) {
    /**
     * @var utorrent|transmission|vuze|deluge|ktorrent|rtorrent|qbittorrent|flood $client
     */
    $client = new $torrentClientData['cl'](
        $torrentClientData['ssl'],
        $torrentClientData['ht'],
        $torrentClientData['pt'],
        $torrentClientData['lg'],
        $torrentClientData['pw']
    );
    // доступность торрент-клиента
    if ($client->isOnline() === false) {
        Log::append('Торрент-клиент ' . $torrentClientData['cm'] . ' (' . $torrentClientData['cl'] . ') в данный момент недоступен');
        continue;
    }
    // применяем таймауты
    $client->setUserConnectionOptions($cfg['curl_setopt']['torrent_client']);
    // получаем список торрентов
    $torrents = $client->getAllTorrents();
    if ($torrents === false) {
        Log::append('Error: Не удалось получить данные о раздачах от торрент-клиента "' . $torrentClientData['cm'] . '"');
        continue;
    }
    Log::append($torrentClientData['cm'] . ' (' . $torrentClientData['cl'] . ') получено раздач: ' . count($torrents) . '  шт.');
    $insertedTorrents = [];
    foreach ($torrents as $torrentHash => $torrentData) {
        $topicID = '';
        // поисковый домен
        $currentSearchDomain = 'rutracker';
        if ($cfg['forum_url'] != 'custom') {
            $currentSearchDomain = $cfg['forum_url'];
        } elseif (!empty($cfg['forum_url_custom'])) {
            $currentSearchDomain = $cfg['forum_url_custom'];
        }
        // если комментарий содержит подходящий домен
        if (
            strpos($torrentData['comment'], 'rutracker') !== false
            || strpos($torrentData['comment'], $currentSearchDomain) !== false
        ) {
            $topicID = preg_replace('/.*?([0-9]*)$/', '$1', $torrentData['comment']);
        }
        $insertedTorrents[] = array_combine(
            $torrentsFields,
            [
                $torrentHash,
                $topicID,
                $torrentClientID,
                $torrentData['done'],
                $torrentData['error'],
                $torrentData['name'],
                $torrentData['paused'],
                $torrentData['time_added'],
                $torrentData['total_size'],
                $torrentData['tracker_error']
            ]
        );
    }
    unset($torrents);
    $insertedTorrents = array_chunk($insertedTorrents, 500);
    foreach ($insertedTorrents as $insertedTorrents) {
        $select = Db::unionQuery($insertedTorrents);
        Db::query_database('INSERT INTO temp.TorrentsNew (' . $torrentsColumns . ') ' . $select);
        unset($select);
    }
    unset($insertedTorrents);
}

$numberTorrentClients = Db::query_database(
    'SELECT COUNT() FROM temp.TorrentsNew',
    [],
    true,
    PDO::FETCH_COLUMN
);

if ($numberTorrentClients[0] > 0) {
    Db::query_database(
        'INSERT INTO Torrents (' . $torrentsColumns . ') SELECT * FROM temp.TorrentsNew'
    );
    Db::query_database(
        'DELETE FROM Torrents WHERE info_hash || client_id NOT IN (
            SELECT Torrents.info_hash || Torrents.client_id
            FROM temp.TorrentsNew
            LEFT JOIN Torrents ON temp.TorrentsNew.info_hash = Torrents.info_hash AND temp.TorrentsNew.client_id = Torrents.client_id
            WHERE Torrents.info_hash IS NOT NULL
        ) OR client_id NOT IN (
            SELECT DISTINCT client_id FROM temp.TorrentsNew
        )'
    );
}

if (isset($cfg['subsections'])) {
    $forumsIDs = array_keys($cfg['subsections']);
    $placeholders = str_repeat('?,', count($forumsIDs) - 1) . '?';
} else {
    $forumsIDs = [];
    $placeholders = '';
}

$untrackedTorrentHashes = Db::query_database(
    'SELECT temp.TorrentsNew.info_hash FROM temp.TorrentsNew
    LEFT JOIN Topics ON Topics.hs = temp.TorrentsNew.info_hash
    WHERE
        Topics.id IS NULL
        OR Topics.ss NOT IN (' . $placeholders . ')',
    $forumsIDs,
    true,
    PDO::FETCH_COLUMN
);

if (!empty($untrackedTorrentHashes)) {
    Log::append('Найдено сторонних раздач: ' . count($untrackedTorrentHashes) . ' шт.');
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
            if (in_array($topicData['tor_status'], [7])) {
                continue;
            }
            $insertedUntrackedTopics[] = [
                'id' => $topicID,
                'ss' => $topicData['forum_id'],
                'na' => $topicData['topic_title'],
                'hs' => $topicData['info_hash'],
                'se' => $topicData['seeders'],
                'si' => $topicData['size'],
                'st' => $topicData['tor_status'],
                'rg' => $topicData['reg_time'],
            ];
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
            [],
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

$topicsUnregistered = Db::query_database(
    'SELECT
        Torrents.info_hash,
        Torrents.topic_id
    FROM Torrents
    LEFT JOIN Topics ON Topics.hs = Torrents.info_hash
    LEFT JOIN TopicsUntracked ON TopicsUntracked.hs = Torrents.info_hash
    WHERE
        Topics.hs IS NULL
        AND TopicsUntracked.hs IS NULL
        AND Torrents.topic_id IS NOT ""
    ORDER BY Torrents.name',
    [],
    true,
    PDO::FETCH_KEY_PAIR
);

if (!empty($topicsUnregistered)) {
    if (!isset($reports)) {
        $reports = new Reports($cfg['forum_address'], $cfg['tracker_login'], $cfg['tracker_paswd']);
        $reports->curl_setopts($cfg['curl_setopt']['forum']);
    }
    $insertedUnregisteredTopics = [];
    foreach ($topicsUnregistered as $infoHash => $topicID) {
        $topicData = $reports->getDataUnregisteredTopic($topicID);
        if ($topicData === false) {
            continue;
        }
        $insertedUnregisteredTopics[] = [
            $infoHash,
            $topicData['name'],
            $topicData['status'],
            $topicData['priority'],
            $topicData['transferred_from'],
            $topicData['transferred_to'],
            $topicData['transferred_by_whom']
        ];
    }
    unset($topicsUnregistered);
    $insertedUnregisteredTopics = array_chunk($insertedUnregisteredTopics, 500);
    foreach ($insertedUnregisteredTopics as $insertedUnregisteredTopics) {
        $select = Db::unionQuery($insertedUnregisteredTopics);
        unset($insertedUnregisteredTopics);
        Db::query_database('INSERT INTO temp.TopicsUnregisteredNew ' . $select);
        unset($select);
    }
    unset($insertedUnregisteredTopics);
    $numberUnregisteredTopics = Db::query_database(
        'SELECT COUNT() FROM temp.TopicsUnregisteredNew',
        [],
        true,
        PDO::FETCH_COLUMN
    );
    if ($numberUnregisteredTopics[0] > 0) {
        Db::query_database(
            'INSERT INTO TopicsUnregistered (
                info_hash,
                name,
                status,
                priority,
                transferred_from,
                transferred_to,
                transferred_by_whom
            )
            SELECT * FROM temp.TopicsUnregisteredNew'
        );
    }
}

Db::query_database(
    'DELETE FROM TopicsUnregistered
    WHERE info_hash NOT IN (
        SELECT info_hash FROM temp.TopicsUnregisteredNew
    )'
);
