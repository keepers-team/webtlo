<?php

include_once dirname(__FILE__) . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\Legacy\Db;
use KeepersTeam\Webtlo\Timers;

$app = AppContainer::create();

$logger = $app->getLogger();

Timers::start('control');
$logger->info('Начат процесс регулировки раздач в торрент-клиентах...');

// получение настроек
$cfg = $app->getLegacyConfig();

// проверка настроек
if (empty($cfg['clients'])) {
    throw new RuntimeException('Error: Не удалось получить список торрент-клиентов');
}
if (empty($cfg['subsections'])) {
    throw new RuntimeException('Error: Не выбраны хранимые подразделы');
}

if (isset($checkEnabledCronAction)) {
    $checkEnabledCronAction = $cfg['automation'][$checkEnabledCronAction] ?? -1;
    if ($checkEnabledCronAction == 0) {
        throw new RuntimeException('Notice: Автоматическая регулировка раздач отключена в настройках.');
    }
}

// Подключение к Api.
$apiClient = $app->getApiClient();

$forumsIDs = array_keys($cfg['subsections']);
$placeholdersForumsIDs = str_repeat('?,', count($forumsIDs) - 1) . '?';

// Количество исключаемых из регулировки хранителей на раздаче.
$keepersExclude = (int)$cfg['topics_control']['keepers'];

$clientFactory = $app->getClientFactory();

$excludedForums = [];
foreach ($cfg['clients'] as $torrentClientID => $torrentClientData) {
    $clientTag = sprintf('%s (%s)', $torrentClientData['cm'], $torrentClientData['cl']);

    $clientControlPeers = ($torrentClientData['control_peers'] !== "") ? (int)$torrentClientData['control_peers'] : -2;
    if ($clientControlPeers == -1) {
        $logger->notice("Для клиента $clientTag отключена регулировка.");
        continue;
    }

    Timers::start("control_client_$torrentClientID");
    try {
        $client = $clientFactory->fromConfigProperties($torrentClientData);
        // проверка доступности торрент-клиента
        if ($client->isOnline() === false) {
            $logger->notice("Клиент $clientTag в данный момент недоступен.");
            continue;
        }
    } catch (Exception $e) {
        $logger->warning("Клиент $clientTag в данный момент недоступен. ". $e->getMessage());
        continue;
    }

    // получение данных от торрент-клиента
    $logger->info("Получаем раздачи торрент-клиента $clientTag");
    Timers::start("get_client_$torrentClientID");
    try {
        $torrents = $client->getTorrents(['simple' => true]);
    } catch (Exception $e) {
        $logger->error(sprintf('Не удалось получить данные о раздачах от торрент-клиента %s, %s', $clientTag, $e->getMessage()));
        continue;
    }

    $logger->info(sprintf(
        '%s получено раздач: %d шт за %s',
        $clientTag,
        $torrents->count(),
        Timers::getExecTime("get_client_$torrentClientID")
    ));

    Timers::start("get_topics_$torrentClientID");

    $unaddedHashes = $torrents->getHashes();
    // ограничение на количество хэшей за раз
    $placeholdersLimit = 999;
    $torrentsHashes = array_chunk(
        $unaddedHashes,
        $placeholdersLimit - count($forumsIDs)
    );

    $topicsHashes = [];
    // вытаскиваем из базы хэши раздач только для хранимых подразделов
    foreach ($torrentsHashes as $torrentsHashes) {
        $placeholdersTorrentsHashes = str_repeat('?,', count($torrentsHashes) - 1) . '?';
        $responseTopicsHashes = Db::query_database(
            '
                SELECT forum_id, info_hash
                FROM Topics
                WHERE
                    info_hash IN (' . $placeholdersTorrentsHashes . ')
                    AND forum_id IN (' . $placeholdersForumsIDs . ')
            ',
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
    $logger->info(sprintf(
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

    $controlTopics = [];
    if (!empty($topicsHashes)) {
        foreach ($topicsHashes as $forumID => $hashes) {
            // пропустим не хранимые подразделы, если их регулировка отключена
            if (!$cfg['topics_control']['unadded_subsections'] && !isset($cfg['subsections'][$forumID])) {
                continue;
            }
            // пропустим исключённые из регулировки подразделы
            $subControlPeers = $cfg['subsections'][$forumID]['control_peers'] ?? -2;
            $subControlPeers = ($subControlPeers !== "") ? (int)$subControlPeers : -2;
            if ($subControlPeers === -1) {
                $excludedForums[] = $forumID;
                continue;
            }
            // регулируемое значение пиров
            $controlPeers = get_control_peers($cfg['topics_control']['peers'], $clientControlPeers, $subControlPeers);

            Timers::start("subsection_$forumID");
            // получаем данные о пирах
            $response = $apiClient->getPeerStats($hashes);
            if ($response instanceof ApiError) {
                $logger->error(sprintf('%d %s', $response->code, $response->text));
                throw new RuntimeException('Error: Не получены данные о пирах раздач.');
            }

            foreach ($response->peers as $topic) {
                $topicHash = (string)$topic->identifier;

                $torrent = $torrents->getTorrent($topicHash);
                if (
                    // пропускаем отсутствующий торрент
                    null === $torrent
                    // пропускаем торрент с ошибкой
                    || $torrent->error
                    // пропускаем торрент на загрузке
                    || $torrent->done != 1
                ) {
                    continue;
                }

                // статус торрента
                $torrentStatus = $torrent->paused ? -1 : 1;
                // учитываем себя
                $seeders = $topic->seeders - ($topic->seeders ? $torrentStatus : 0);
                // находим значение личей
                $leechers = $cfg['topics_control']['leechers'] ? $topic->leechers : 0;

                // количество сидов-хранителей раздачи, которых нужно вычесть из счётчика
                $keepers = 0;
                if ($keepersExclude > 0) {
                    // хранители раздачи, исключая себя
                    $keepers = count(array_diff((array)$topic->keepers, [$cfg['user_id']]));
                    $keepers = min($keepers, $keepersExclude);
                }

                // находим значение пиров
                $peers = $seeders + $leechers - $keepers;
                // учитываем вновь прибывшего "лишнего" сида
                if (
                    $seeders
                    && $peers == $controlPeers
                    && $torrentStatus === 1
                ) {
                    $peers++;
                }

                // стопим только, если есть сиды
                $peersState = $peers > $controlPeers
                    || !$cfg['topics_control']['no_leechers']
                    && !$topic->leechers;

                if ($seeders && $peersState) {
                    if ($torrentStatus === 1) {
                        $controlTopics['stop'][] = $topicHash;
                    }
                } else {
                    if ($torrentStatus === -1) {
                        $controlTopics['start'][] = $topicHash;
                    }
                }

                unset($topic, $topicHash);
                unset($torrent, $torrentStatus);
                unset($seeders, $leechers, $keepers, $peers, $peersState);
            }

            $logger->debug('Обработка раздела', [
                'forumId'   => $forumID,
                'count'     => count($hashes),
                'peerLimit' => $controlPeers,
                'execTime'  => Timers::getExecTime("subsection_$forumID"),
            ]);

            unset($forumID, $hashes, $response);
        }
    }
    if (empty($controlTopics)) {
        $logger->notice("Регулировка раздач не требуется для торрент-клиента $clientTag");
        continue;
    }

    // запускаем
    if (!empty($controlTopics['start'])) {
        $totalStartedTopics = count($controlTopics['start']);
        $controlTopics['start'] = array_chunk($controlTopics['start'], 100);
        foreach ($controlTopics['start'] as $hashes) {
            $response = $client->startTorrents($hashes);
            if ($response === false) {
                $logger->error('Возникли проблемы при отправке запроса на запуск раздач.');
            }
        }
        $logger->info("Запрос на запуск раздач торрент-клиенту $clientTag отправлен ($totalStartedTopics шт).");
    }

    // останавливаем
    if (!empty($controlTopics['stop'])) {
        $totalStoppedTopics = count($controlTopics['stop']);
        $controlTopics['stop'] = array_chunk($controlTopics['stop'], 100);
        foreach ($controlTopics['stop'] as $hashes) {
            $response = $client->stopTorrents($hashes);
            if ($response === false) {
                $logger->error('Возникли проблемы при отправке запроса на остановку раздач.');
            }
        }
        $logger->info("Запрос на остановку раздач торрент-клиенту $clientTag отправлен ($totalStoppedTopics шт).");
    }
    unset($controlTopics);

    $logger->info(sprintf(
        'Регулировка раздач в торрент-клиенте %s завершена за %s',
        $clientTag,
        Timers::getExecTime("control_client_$torrentClientID")
    ));
}
if (count($excludedForums)) {
    $excludedForums = array_unique($excludedForums);
    $logger->debug(sprintf('Регулировка отключена для подразделов №№ %s.', implode(', ', $excludedForums)));
}
$logger->info('Регулировка раздач в торрент-клиентах завершена за ' . Timers::getExecTime('control'));


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
