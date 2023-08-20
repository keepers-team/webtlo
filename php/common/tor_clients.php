<?php

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/clients.php';
include_once dirname(__FILE__) . '/../classes/api.php';
include_once dirname(__FILE__) . '/../classes/reports.php';

use KeepersTeam\Webtlo\Module\CloneTable;
use KeepersTeam\Webtlo\Module\Topics;

// получение настроек
if (!isset($cfg)) {
    $cfg = get_settings();
}

if (empty($cfg['clients'])) {
    Db::query_database("DELETE FROM Torrents");
    return;
}
Log::append(sprintf('Сканирование торрент-клиентов... Найдено %d шт.', count($cfg['clients'])));

// Таблица хранимых раздач в торрент-клиентах.
$tabTorrents = CloneTable::create(
    'Torrents',
    [
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
    ],
    'info_hash'
);

// Таблица хранимых раздач из других подразделов.
$tabUntracked = CloneTable::create(
    'TopicsUntracked',
    ['id','ss','na','hs','se','si','st','rg']
);

// Таблица хранимых раздач, более не зарегистрированных на трекере.
$tabUnregistered = CloneTable::create(
    'TopicsUnregistered',
    ['info_hash','name','status','priority','transferred_from','transferred_to','transferred_by_whom'],
    'info_hash'
);


$timers = [];
Timers::start('update_clients');
foreach ($cfg['clients'] as $torrentClientID => $torrentClientData) {
    Timers::start("update_client_$torrentClientID");
    $clientTag = sprintf('%s (%s)', $torrentClientData['cm'], $torrentClientData['cl']);

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
        Log::append("Торрент-клиент $clientTag в данный момент недоступен");
        continue;
    }
    // применяем таймауты
    $client->setUserConnectionOptions($cfg['curl_setopt']['torrent_client']);

    // получаем список раздач
    $torrents = $client->getAllTorrents();
    if ($torrents === false) {
        Log::append("Error: Не удалось получить данные о раздачах от торрент-клиента $clientTag");
        continue;
    }

    $insertedTorrents = [];
    $countTorrents = count($torrents);
    foreach ($torrents as $torrentHash => $torrentData) {
        // TODO вынести в функцию
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
            $tabTorrents->keys,
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

        unset($torrentHash, $torrentData, $topicID, $currentSearchDomain);
    }
    unset($torrents);

    // Запишем данные хранимых раздач во временную таблицу.
    $tabTorrents->cloneFillChunk($insertedTorrents);
    unset($insertedTorrents);

    Log::append(sprintf('%s получено раздач: %d шт за %s',
        $clientTag,
        $countTorrents,
        Timers::getExecTime("update_client_$torrentClientID")
    ));

    unset($torrentClientID, $torrentClientData, $countTorrents);
}

$timers['update_clients'] = Timers::getExecTime('update_clients');

// Добавим в БД полученные данные о раздачах.
if ($tabTorrents->cloneCount() > 0) {
    $tabTorrents->moveToOrigin();

    // Удалим лишние раздачи из БД.
    Db::query_database(
        "DELETE FROM $tabTorrents->origin WHERE info_hash || client_id NOT IN (
            SELECT ins.info_hash || ins.client_id
            FROM $tabTorrents->clone AS tmp
            LEFT JOIN $tabTorrents->origin AS ins ON tmp.info_hash = ins.info_hash AND tmp.client_id = ins.client_id
            WHERE ins.info_hash IS NOT NULL
        ) OR client_id NOT IN (
            SELECT DISTINCT client_id FROM $tabTorrents->clone
        )"
    );
}

if (isset($cfg['subsections'])) {
    $forumsIDs = array_keys($cfg['subsections']);
    $placeholders = str_repeat('?,', count($forumsIDs) - 1) . '?';
} else {
    $forumsIDs = [];
    $placeholders = '';
}


Timers::start('search_untracked');
// Найдём раздачи из нехранимых подразделов.
$untrackedTorrentHashes = Db::query_database(
    "SELECT tmp.info_hash
    FROM $tabTorrents->clone AS tmp
    LEFT JOIN Topics ON Topics.hs = tmp.info_hash
    WHERE
        Topics.id IS NULL
        OR Topics.ss NOT IN ($placeholders)",
    $forumsIDs,
    true,
    PDO::FETCH_COLUMN
);

if (!empty($untrackedTorrentHashes)) {
    Log::append('Найдено уникальных сторонних раздач в клиентах: ' . count($untrackedTorrentHashes) . ' шт.');
    // подключаемся к api
    if (!isset($api)) {
        $api = new Api($cfg['api_address'], $cfg['api_key']);
        // применяем таймауты
        $api->setUserConnectionOptions($cfg['curl_setopt']['api']);
    }

    // Пробуем найти на форуме раздачи по их хешам из клиента.
    $untrackedTopics = $api->getTorrentTopicData($untrackedTorrentHashes, 'hash');
    unset($untrackedTorrentHashes);
    if (!empty($untrackedTopics)) {
        foreach ($untrackedTopics as $topicID => $topicData) {
            if (empty($topicData)) {
                continue;
            }
            // Пропускаем раздачи в невалидных статусах.
            if (!in_array($topicData['tor_status'], Topics::VALID_STATUSES)) {
                continue;
            }
            $insertedUntrackedTopics[] = array_combine(
                $tabUntracked->keys,
                [
                    $topicID,
                    $topicData['forum_id'],
                    $topicData['topic_title'],
                    $topicData['info_hash'],
                    $topicData['seeders'],
                    $topicData['size'],
                    $topicData['tor_status'],
                    $topicData['reg_time'],
                ]
            );
        }
        unset($untrackedTopics);

        // Если нашлись существующие на форуме раздачи, то записываем их в БД.
        if (!empty($insertedUntrackedTopics)) {
            Log::append(sprintf('Записано уникальных сторонних раздач: %d шт.', count($insertedUntrackedTopics)));

            $tabUntracked->cloneFillChunk($insertedUntrackedTopics);
            unset($insertedUntrackedTopics);

            if ($tabUntracked->cloneCount() > 0) {
                $tabUntracked->moveToOrigin();
            }
        }
    }
}
// Удалим лишние раздачи из БД нехранимых.
$tabUntracked->clearUnusedRows();
$timers['search_untracked'] = Timers::getExecTime('search_untracked');


// Найдём разрегистрированные раздачи.
Timers::start('search_unregistered');
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
        AND Torrents.topic_id IS NOT ""',
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
        $insertedUnregisteredTopics[] = array_combine(
            $tabUnregistered->keys,
            [
                $infoHash,
                $topicData['name'],
                $topicData['status'],
                $topicData['priority'],
                $topicData['transferred_from'],
                $topicData['transferred_to'],
                $topicData['transferred_by_whom']
            ]
        );
    }
    unset($topicsUnregistered);

    $tabUnregistered->cloneFillChunk($insertedUnregisteredTopics);
    unset($insertedUnregisteredTopics);

    $countUnregistered = $tabUnregistered->cloneCount();
    if ($countUnregistered > 0) {
        Log::append(sprintf('Обнаружено разрегистрированных или обновлённых раздач: %d шт.', $countUnregistered));
        $tabUnregistered->moveToOrigin();
    }
}
// Удалим лишние раздачи из БД разрегов.
$tabUnregistered->clearUnusedRows();
$timers['search_unregistered'] = Timers::getExecTime('search_unregistered');

Log::append(json_encode($timers));