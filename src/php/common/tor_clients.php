<?php

include_once dirname(__FILE__) . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Clients\ClientFactory;
use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\KeepingPriority;
use KeepersTeam\Webtlo\External\Api\V1\TopicSearchMode;
use KeepersTeam\Webtlo\External\Api\V1\TorrentStatus;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Legacy\Db;
use KeepersTeam\Webtlo\Storage\Clone\TopicsUnregistered;
use KeepersTeam\Webtlo\Storage\Clone\TopicsUntracked;
use KeepersTeam\Webtlo\Storage\Clone\Torrents;
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

/**
 * Таблица хранимых раздач в торрент-клиентах.
 *
 * @var Torrents $cloneTorrents
 */
$cloneTorrents = $app->get(Torrents::class);

/**
 * Таблица хранимых раздач из других подразделов.
 *
 * @var TopicsUntracked $cloneUntracked
 */
$cloneUntracked = $app->get(TopicsUntracked::class);

/**
 * Таблица хранимых раздач, которые разрегистрированы на форуме.
 *
 * @var TopicsUnregistered $cloneUnregistered
 */
$cloneUnregistered = $app->get(TopicsUnregistered::class);

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

    $countTorrents = count($torrents);
    foreach ($torrents->getGenerator() as $torrent) {
        $cloneTorrents->addTorrent($torrentClientID, $torrent);
    }
    unset($torrents);

    // Запишем данные хранимых раздач во временную таблицу.
    $cloneTorrents->cloneFill();

    $logger->info('{client} получено раздач: {count} шт за {sec}', [
        'client' => $clientTag,
        'count'  => $countTorrents,
        'sec'    => Timers::getExecTime("update_client_$torrentClientID"),
    ]);

    unset($torrentClientID, $torrentClientData, $countTorrents);
}

$timers['update_clients'] = Timers::getExecTime('update_clients');

// Добавим в БД полученные данные о раздачах.
$cloneTorrents->writeTable();

// Если обновление всех не исключённых клиентов прошло успешно - отметим это.
if (!count(array_diff($failedClients, $excludedClients))) {
    $updateTime->setMarkerTime(UpdateMark::CLIENTS->value);
}

$failedClients = KeysObject::create($failedClients);
// Удалим лишние раздачи из БД.
$cloneTorrents->removeUnusedTorrentsRows(failedClients: $failedClients);


$unregisteredApiTopics = [];
// Найдём раздачи из не хранимых подразделов.
if ($cfg['update']['untracked']) {
    Timers::start('search_untracked');
    $subsections = KeysObject::create(array_keys($cfg['subsections'] ?? []));

    $untrackedTorrentHashes = $cloneTorrents->selectUntrackedRows(subsections: $subsections);

    if (count($untrackedTorrentHashes)) {
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

                $cloneUntracked->addTopic(topic: $topicData);
            }

            // Если нашлись существующие на форуме раздачи, то записываем их в БД.
            $cloneUntracked->moveToOrigin();
        }
        unset($untrackedTorrentHashes, $response);
    }

    $timers['search_untracked'] = Timers::getExecTime('search_untracked');
}
// Удалим лишние раздачи из БД прочих.
$cloneUntracked->clearUnusedRows();


// Найдём разрегистрированные раздачи.
if ($cfg['update']['untracked'] && $cfg['update']['unregistered']) {
    Timers::start('search_unregistered');

    try {
        $unregisteredTopics = $cloneUnregistered->searchUnregisteredTopics();

        // Если в БД есть разрегистрированные раздачи, ищем их статус на форуме.
        if (count($unregisteredTopics)) {
            $forumClient = $app->getForumClient();

            if (!$forumClient->checkConnection()) {
                throw new RuntimeException('Ошибка подключения к форуму. Поиск прекращён.');
            }

            foreach ($unregisteredTopics as $topicID => $infoHash) {
                $topicData = $forumClient->getUnregisteredTopic((int)$topicID);
                if (null === $topicData) {
                    continue;
                }

                // Если о раздаче есть данные в API, то дописываем их, как более верные.
                if (!empty($unregisteredApiTopics[$infoHash])) {
                    $topic = $unregisteredApiTopics[$infoHash];

                    $topicData['name'] = $topic->title;
                    $topicData['status'] = $topic->status->label();
                    if (empty($topicData['priority'])) {
                        $topicData['priority'] = KeepingPriority::Normal->label();
                    }

                    unset($topic);
                }

                $cloneUnregistered->addTopic([
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

            $cloneUnregistered->fillTempTable();
        }
    } catch (Exception $e) {
        $logger->warning('Ошибка при поиске разрегистрированных раздач. {error}', ['error' => $e->getMessage()]);
    }
    $cloneUnregistered->moveToOrigin();

    $timers['search_unregistered'] = Timers::getExecTime('search_unregistered');
}
$cloneUnregistered->clearUnusedRows();

$logger->debug((string)json_encode($timers));
