<?php

include_once dirname(__FILE__) . '/../../vendor/autoload.php';
include_once dirname(__FILE__) . '/../classes/reports.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Clients\ClientFactory;
use KeepersTeam\Webtlo\Config\Validate as ConfigValidate;
use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\KeepingPriority;
use KeepersTeam\Webtlo\External\Api\V1\TopicSearchMode;
use KeepersTeam\Webtlo\External\Api\V1\TorrentStatus;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Legacy\Db;
use KeepersTeam\Webtlo\Module\CloneTable;
use KeepersTeam\Webtlo\Module\Topics;
use KeepersTeam\Webtlo\Tables\TopicsUnregistered;
use KeepersTeam\Webtlo\Tables\UpdateTime;
use KeepersTeam\Webtlo\Timers;

$app = AppContainer::create();

// получение настроек
$cfg = $app->getLegacyConfig();

$logger = $app->getLogger();

/** @var UpdateTime $updateTime */
$updateTime = $app->get(UpdateTime::class);

// Если нет настроенных торрент-клиентов, удалим все раздачи и отметку.
if (empty($cfg['clients'])) {
    $logger->notice('Торрент-клиенты не найдены.');

    $updateTime->setMarkerTime(UpdateMark::CLIENTS->value, 0);
    Db::query_database('DELETE FROM Torrents WHERE true');
    return;
}
$logger->info(sprintf('Сканирование торрент-клиентов... Найдено %d шт.', count($cfg['clients'])));

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
    ['id','forum_id','name','info_hash','seeders','size','status','reg_time'],
);


$timers = [];

$forumDomain = Helper::getForumDomain($cfg);

/** Клиенты, данные от которых получить не удалось */
$failedClients = [];
/** Клиенты исключённые из формирования отчётов и для успешного обновления - не обязательны. */
$excludedClients = [];
Timers::start('update_clients');

/** @var ClientFactory $clientFactory */
$clientFactory = $app->get(ClientFactory::class);

foreach ($cfg['clients'] as $torrentClientID => $torrentClientData) {
    Timers::start("update_client_$torrentClientID");
    $clientTag = sprintf('%s (%s)', $torrentClientData['cm'], $torrentClientData['cl']);

    // Признак исключения раздач клиента из формируемых отчётов.
    if ($torrentClientData['exclude'] ?? false) {
        $excludedClients[] = $torrentClientID;
    }

    try {
        // Подключаемся к торрент-клиенту.
        $client = $clientFactory->fromConfigProperties($torrentClientData);
    } catch (RuntimeException $e) {
        $logger->notice("Клиент $clientTag в данный момент недоступен", [$e->getMessage()]);
        $failedClients[] = $torrentClientID;

        continue;
    }

    // Меняем домен трекера, для корректного поиска раздач.
    $client->setDomain($forumDomain);

    // получаем список раздач
    try {
        $torrents = $client->getTorrents();
    } catch (RuntimeException $e) {
        $logger->warning("Не удалось получить данные о раздачах от торрент-клиента $clientTag", [$e->getMessage()]);
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

    $logger->info(sprintf(
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
    $updateTime->setMarkerTime(UpdateMark::CLIENTS->value);
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


$unregisteredApiTopics = [];
// Найдём раздачи из не хранимых подразделов.
if ($cfg['update']['untracked']) {
    Timers::start('search_untracked');
    $subsections = KeysObject::create(array_keys($cfg['subsections'] ?? []));

    $untrackedTorrentHashes = Db::query_database(
        "
            SELECT tmp.info_hash
            FROM $tabTorrents->clone AS tmp
            LEFT JOIN Topics ON Topics.info_hash = tmp.info_hash
            WHERE
                Topics.id IS NULL
                OR Topics.forum_id NOT IN ($subsections->keys)
        ",
        $subsections->values,
        true,
        PDO::FETCH_COLUMN
    );

    if (!empty($untrackedTorrentHashes)) {
        $logger->info('Найдено уникальных сторонних раздач в клиентах: ' . count($untrackedTorrentHashes) . ' шт.');
        // подключаемся к api
        $apiClient = $app->getApiClient();

        // Пробуем найти в API раздачи по их хешам из клиента.
        $response = $apiClient->getTopicsDetails($untrackedTorrentHashes, TopicSearchMode::HASH);

        if ($response instanceof ApiError) {
            $logger->debug(
                sprintf('Не удалось найти данные о раздачах в API. %d %s', $response->code, $response->text)
            );
            $logger->debug('hashes', $untrackedTorrentHashes);
        } elseif (!empty($response->topics)) {
            foreach ($response->topics as $topicData) {
                // Пропускаем раздачи в невалидных статусах.
                if (!TorrentStatus::isValidStatus($topicData->status)) {
                    $unregisteredApiTopics[$topicData->hash] = $topicData;

                    continue;
                }

                $insertedUntrackedTopics[] = array_combine(
                    $tabUntracked->keys,
                    [
                        $topicData->id,
                        $topicData->forumId,
                        $topicData->title,
                        $topicData->hash,
                        $topicData->seeders,
                        $topicData->size,
                        $topicData->status->value,
                        $topicData->registered->getTimestamp(),
                    ]
                );
            }
            unset($untrackedTopics);

            // Если нашлись существующие на форуме раздачи, то записываем их в БД.
            if (!empty($insertedUntrackedTopics)) {
                $logger->info(sprintf('Записано уникальных сторонних раздач: %d шт.', count($insertedUntrackedTopics)));

                $tabUntracked->cloneFillChunk($insertedUntrackedTopics);
                unset($insertedUntrackedTopics);

                if ($tabUntracked->cloneCount() > 0) {
                    $tabUntracked->moveToOrigin();
                }
            }
        }
        unset($untrackedTorrentHashes, $response);
    }

    $timers['search_untracked'] = Timers::getExecTime('search_untracked');
}
// Удалим лишние раздачи из БД прочих.
$tabUntracked->clearUnusedRows();


/** @var TopicsUnregistered $tabUnregistered */
$tabUnregistered = $app->get(TopicsUnregistered::class);

// Найдём разрегистрированные раздачи.
if ($cfg['update']['untracked'] && $cfg['update']['unregistered']) {
    Timers::start('search_unregistered');

    try {
        $unregisteredTopics = $tabUnregistered->searchUnregisteredTopics();

        // Если в БД есть разрегистрированные раздачи, ищем их статус на форуме.
        if (!empty($unregisteredTopics)) {
            $user = ConfigValidate::checkUser($cfg);

            $forumReports = new Reports($forumDomain, $user);
            $forumReports->curl_setopts($cfg['curl_setopt']['forum']);

            foreach ($unregisteredTopics as $topicID => $infoHash) {
                $topicData = $forumReports->getDataUnregisteredTopic((int)$topicID);
                if (null === $topicData) {
                    continue;
                }

                // Если о раздаче есть данные в API, то дописываем их, как более верные.
                if (!empty($unregisteredApiTopics[$infoHash])) {
                    $topic = $unregisteredApiTopics[$infoHash];

                    $topicData['name']   = $topic->title;
                    $topicData['status'] = $topic->status->label();
                    if (empty($topicData['priority'])) {
                        $topicData['priority'] = KeepingPriority::Normal->label();
                    }

                    unset($topic);
                }

                $tabUnregistered->addTopic([
                    $infoHash,
                    $topicData['name'],
                    $topicData['status'],
                    $topicData['priority'],
                    $topicData['transferred_from'],
                    $topicData['transferred_to'],
                    $topicData['transferred_by_whom'],
                ]);
            }
            unset($unregisteredTopics, $unregisteredApiTopics);

            $tabUnregistered->fillTempTable();
        }
    } catch (Exception $e) {
        $logger->warning('Ошибка при поиске разрегистрированных раздач. {error}', ['error' => $e->getMessage()]);
    }
    $tabUnregistered->moveToOrigin();

    $timers['search_unregistered'] = Timers::getExecTime('search_unregistered');
}
$tabUnregistered->clearUnusedRows();

$logger->debug(json_encode($timers));
