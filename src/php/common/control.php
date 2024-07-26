<?php

include_once dirname(__FILE__) . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\Action\Control;
use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Config\TopicControl;
use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\Module\ControlHelper;
use KeepersTeam\Webtlo\Timers;

$app = AppContainer::create('control.log');

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

// Хранимые подразделы.
$forums = KeysObject::create(array_keys($cfg['subsections']));

/** @var TopicControl $topicControl Параметры регулировки */
$topicControl = $app->get(TopicControl::class);

/** @var Control $actionControl Выполнение регулировки */
$actionControl = $app->get(Control::class);

/** @var ControlHelper $apiHelper Получение раздач из API */
$apiHelper = $app->get(ControlHelper::class);
$apiHelper->setCachedSubForums(forums: $actionControl->getRepeatedSubForums());

$clientFactory = $app->getClientFactory();

$excludedForums = [];
foreach ($cfg['clients'] as $torrentClientID => $torrentClientData) {
    $torrentClientID = (int)$torrentClientID;

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

    $logger->info('{tag} получено раздач: {count} шт за {sec}', [
        'tag'   => $clientTag,
        'count' => $torrents->count(),
        'sec'   => Timers::getExecTime("get_client_$torrentClientID"),
    ]);

    // Получаем раздачи из БД.
    $topicsHashes = $actionControl->getStoredHashes($torrentClientID, $forums, $torrents);

    // Счётчики применения фортуны при переключении состояния раздачи.
    $randomCounter = $randomProc = 0;

    $controlTopics = ['stop' => [], 'start' => []];
    foreach ($topicsHashes as $forumID => $hashes) {
        // Пропустим подраздел, есть его нет в списке хранимых и регулировка "прочих" отключена.
        if (!$topicControl->manageOtherSubsections && $forumID === Control::UnknownHashes) {
            continue;
        }

        // пропустим исключённые из регулировки подразделы
        $subControlPeers = $cfg['subsections'][$forumID]['control_peers'] ?? -2;
        $subControlPeers = ($subControlPeers !== "") ? (int)$subControlPeers : -2;
        if ($subControlPeers === -1) {
            $excludedForums[] = $forumID;
            continue;
        }

        // Лимит пиров для регулировки.
        $peersLimit = Control::getPeerLimit($topicControl, $clientControlPeers, $subControlPeers);

        Timers::start("subsection_$forumID");
        // Получаем данные о пирах искомых раздач и перебираем их.
        $topicsPeers = $apiHelper->getTopicsPeersGenerator(group: $forumID, hashes: $hashes);
        foreach ($topicsPeers as $topic) {
            // Проверяем наличие и статус раздачи в клиенте.
            $torrent = $torrents->getTorrent(hash: $topic->hash);
            if (
                // пропускаем отсутствующий торрент
                null === $torrent
                // пропускаем торрент с ошибкой
                || $torrent->error
                // пропускаем торрент на загрузке
                || $torrent->done < 1.0
            ) {
                continue;
            }

            // Вычисляем необходимое изменение состояния раздачи в клиенте.
            $desiredChange = $actionControl->determineDesiredState(
                topic    : $topic,
                peerLimit: $peersLimit,
                isSeeding: !$torrent->paused
            );
            if ($desiredChange->isRandom()) {
                $randomCounter++;
            }

            if ($desiredChange->shouldStartSeeding()) {
                if ($desiredChange->isRandom()) {
                    $randomProc++;
                }

                $controlTopics['start'][] = $torrent->clientHash;
            } elseif ($desiredChange->shouldStopSeeding()) {
                if ($desiredChange->isRandom()) {
                    $randomProc++;
                }

                $controlTopics['stop'][] = $torrent->clientHash;
            }

            unset($topic, $torrent);
        }

        $logger->debug('Обработка раздела', [
            'forumId'   => $forumID,
            'count'     => count($hashes),
            'peerLimit' => $peersLimit,
            'execTime'  => Timers::getExecTime("subsection_$forumID"),
        ]);

        unset($forumID, $hashes);
    }

    if (!count($controlTopics['start']) && !count($controlTopics['stop'])) {
        $logger->notice("Регулировка раздач не требуется для торрент-клиента $clientTag", [
            'randomTotal' => $randomCounter,
            'randomProc' => $randomProc,
        ]);
        continue;
    }

    // Запускаем раздачи.
    if (count($controlTopics['start'])) {
        // TODO перекинуть задачу разбиения хешей на чанки торрент-клиентам.
        foreach (array_chunk($controlTopics['start'], 100) as $hashes) {
            $response = $client->startTorrents($hashes);
            if ($response === false) {
                $logger->error('Возникли проблемы при отправке запроса на запуск раздач.');
            }
        }
    }

    // Останавливаем раздачи.
    if (count($controlTopics['stop'])) {
        // TODO перекинуть задачу разбиения хешей на чанки торрент-клиентам.
        foreach (array_chunk($controlTopics['stop'], 100) as $hashes) {
            $response = $client->stopTorrents($hashes);
            if ($response === false) {
                $logger->error('Возникли проблемы при отправке запроса на остановку раздач.');
            }
        }
    }

    $logger->info('Регулировка раздач в торрент-клиенте {tag} завершена за {sec}', [
        'tag'    => $clientTag,
        'sec'    => Timers::getExecTime("control_client_$torrentClientID"),
        'start'  => count($controlTopics['start']),
        'stop'   => count($controlTopics['stop']),
        'total'  => $torrents->count(),
        'random' => $randomCounter,
        'proc'   => $randomProc,
    ]);
    unset($controlTopics);
}

if (count($excludedForums)) {
    $logger->debug('Регулировка отключена для подразделов №№ {excluded}.', [
        'excluded' => implode(', ', array_unique($excludedForums)),
    ]);
}
$logger->info('Регулировка раздач в торрент-клиентах завершена за ' . Timers::getExecTime('control'));
$logger->info('-- DONE --');
