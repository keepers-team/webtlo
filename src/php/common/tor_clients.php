<?php

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/clients.php';
include_once dirname(__FILE__) . '/../classes/api.php';
include_once dirname(__FILE__) . '/../classes/reports.php';

use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Config\Validate as ConfigValidate;
use KeepersTeam\Webtlo\Module\CloneTable;
use KeepersTeam\Webtlo\Module\LastUpdate;
use KeepersTeam\Webtlo\Module\Topics;

// получение настроек
if (!isset($cfg)) {
    $cfg = get_settings();
}

// Если нет настроенных торрент-клиентов, удалим все раздачи и отметку.
if (empty($cfg['clients'])) {
    Log::append('Notice: Торрент-клиенты не найдены.');

    LastUpdate::setTime(UpdateMark::CLIENTS->value, 0);
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

/** Клиенты, данные от которых получить не удалось */
$failedClients = [];
/** Клиенты исключённые из формирования отчётов и для успешного обновления - не обязательны. */
$excludedClients = [];
Timers::start('update_clients');
foreach ($cfg['clients'] as $torrentClientID => $torrentClientData) {
    Timers::start("update_client_$torrentClientID");
    $clientTag = sprintf('%s (%s)', $torrentClientData['cm'], $torrentClientData['cl']);

    /**
     * @var utorrent|transmission|vuze|deluge|rtorrent|qbittorrent|flood $client
     */
    $client = new $torrentClientData['cl'](
        $torrentClientData['ssl'],
        $torrentClientData['ht'],
        $torrentClientData['pt'],
        $torrentClientData['lg'],
        $torrentClientData['pw']
    );
    // Признак исключения раздач клиента из формируемых отчётов.
    if ($torrentClientData['exclude'] ?? false) {
        $excludedClients[] = $torrentClientID;
    }

    // доступность торрент-клиента
    if ($client->isOnline() === false) {
        Log::append("Notice: Клиент $clientTag в данный момент недоступен");
        $failedClients[] = $torrentClientID;
        continue;
    }
    // применяем таймауты
    $client->setUserConnectionOptions($cfg['curl_setopt']['torrent_client']);
    $client->setDomain(Helper::getForumDomain($cfg));

    // получаем список раздач
    $torrents = $client->getAllTorrents();
    if ($torrents === false) {
        Log::append("Error: Не удалось получить данные о раздачах от торрент-клиента $clientTag");
        $failedClients[] = $torrentClientID;
        continue;
    }

    $insertedTorrents = [];
    $countTorrents = count($torrents);
    foreach ($torrents as $torrentHash => $torrentData) {
        $insertedTorrents[] = array_combine(
            $tabTorrents->keys,
            [
                $torrentHash,
                $torrentData['topic_id'],
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

    Log::append(sprintf(
        '%s получено раздач: %d шт за %s',
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
}

// Если обновление всех не исключённых клиентов прошло успешно - отметим это.
if (!count(array_diff($failedClients, $excludedClients))) {
    LastUpdate::setTime(UpdateMark::CLIENTS->value);
}

$failedClients = KeysObject::create($failedClients);
// Удалим лишние раздачи из БД.
Db::query_database("
    DELETE FROM $tabTorrents->origin 
    WHERE client_id NOT IN ($failedClients->keys) AND (
        info_hash || client_id NOT IN (
            SELECT ins.info_hash || ins.client_id
            FROM $tabTorrents->clone AS tmp
            INNER JOIN $tabTorrents->origin AS ins ON tmp.info_hash = ins.info_hash AND tmp.client_id = ins.client_id
        ) OR client_id NOT IN (
            SELECT DISTINCT client_id FROM $tabTorrents->clone
        )
    )
", $failedClients->values);


// Найдём раздачи из нехранимых подразделов.
if ($cfg['update']['untracked']) {
    Timers::start('search_untracked');
    $subsections = KeysObject::create(array_keys($cfg['subsections'] ?? []));

    $untrackedTorrentHashes = Db::query_database(
        "SELECT tmp.info_hash
        FROM $tabTorrents->clone AS tmp
        LEFT JOIN Topics ON Topics.hs = tmp.info_hash
        WHERE
            Topics.id IS NULL
            OR Topics.ss NOT IN ($subsections->keys)",
        $subsections->values,
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

    $timers['search_untracked'] = Timers::getExecTime('search_untracked');
}
// Удалим лишние раздачи из БД нехранимых.
$tabUntracked->clearUnusedRows();


// Найдём разрегистрированные раздачи.
if ($cfg['update']['untracked'] && $cfg['update']['unregistered']) {
    Timers::start('search_unregistered');
    $topicsUnregistered = Db::query_database(
        "SELECT
                Torrents.info_hash,
                Torrents.topic_id
            FROM Torrents
            LEFT JOIN Topics ON Topics.hs = Torrents.info_hash
            LEFT JOIN TopicsUntracked ON TopicsUntracked.hs = Torrents.info_hash
            WHERE
                Topics.hs IS NULL
                AND TopicsUntracked.hs IS NULL
                AND Torrents.topic_id <> ''",
        [],
        true,
        PDO::FETCH_KEY_PAIR
    );

    if (!empty($topicsUnregistered)) {
        if (!isset($reports)) {
            $user = ConfigValidate::checkUser($cfg);

            $reports = new Reports($cfg['forum_address'], $user);
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
            Log::append(sprintf('Найдено разрегистрированных или обновлённых раздач: %d шт.', $countUnregistered));
            $tabUnregistered->moveToOrigin();
        }
    }

    $timers['search_unregistered'] = Timers::getExecTime('search_unregistered');
}
// Удалим лишние раздачи из БД разрегов.
$tabUnregistered->clearUnusedRows();

Log::append(json_encode($timers));