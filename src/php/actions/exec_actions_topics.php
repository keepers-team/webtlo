<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Legacy\Db;
use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\Module\Torrents;

try {
    $result = '';

    // разбираем данные
    $actionType  = $_POST['action'] ?? '';
    $labelName   = $_POST['label'] ?? '';
    $removeFiles = $_POST['remove_data'] ?? '';
    $forceStart  = $_POST['force_start'] ?? '';

    // поддерживаемые действия
    $supportedActions = [
        'set_label',
        'start',
        'stop',
        'remove',
    ];

    if (!in_array($actionType, $supportedActions)) {
        throw new Exception('Попытка выполнить неизвестное действие');
    }
    if (empty($_POST['topic_hashes'])) {
        throw new Exception('Выберите раздачи');
    }
    if (empty($_POST['tor_clients'])) {
        throw new Exception('В настройках не найдены торрент-клиенты');
    }

    $app = AppContainer::create();

    // получение настроек
    $cfg = $app->getLegacyConfig();
    $log = $app->getLogger();

    $clientFactory = $app->getClientFactory();

    $log->info("Начато выполнение действия '$actionType' для выбранных раздач...");
    $log->debug('Получение хэшей раздач с привязкой к торрент-клиенту...');

    $torrentClients = $_POST['tor_clients'];
    parse_str($_POST['topic_hashes'], $topicHashes);
    $topicHashes = Helper::convertKeysToString((array)$topicHashes['topic_hashes']);

    $topicHashesChunks = array_chunk($topicHashes, 499);
    foreach ($topicHashesChunks as $topicHashes) {
        $placeholders = str_repeat('?,', count($topicHashes) - 1) . '?';

        $data = Db::query_database(
            'SELECT
                client_id,
                info_hash
            FROM Torrents
            WHERE info_hash IN (' . $placeholders . ')',
            $topicHashes,
            true,
            PDO::FETCH_GROUP | PDO::FETCH_COLUMN
        );
        unset($placeholders);

        foreach ($data as $clientID => $clientTorrentHashes) {
            if (isset($torrentHashesByClient[$clientID])) {
                $torrentHashesByClient[$clientID] = array_merge(
                    $torrentHashesByClient[$clientID],
                    $clientTorrentHashes
                );
            } else {
                $torrentHashesByClient[$clientID] = $clientTorrentHashes;
            }
            unset($clientID, $clientTorrentHashes);
        }
        unset($data);
    }
    unset($topicHashes);

    if (empty($torrentHashesByClient)) {
        throw new Exception('Не получены данные о выбранных раздачах');
    }

    $log->info('Количество затрагиваемых торрент-клиентов: {client}', ['client' => count($torrentHashesByClient)]);
    foreach ($torrentHashesByClient as $clientID => $torrentHashes) {
        if (empty($torrentHashes)) {
            continue;
        }
        if (empty($torrentClients[$clientID])) {
            $log->warning("В настройках нет данных о торрент-клиенте с идентификатором '$clientID'");
            continue;
        }

        // данные текущего торрент-клиента
        $torrentClient = $torrentClients[$clientID];
        $logRecord     = ['tag' => $torrentClient['comment']];

        try {
            $client = $clientFactory->fromFrontProperties($torrentClient);

            // проверка доступности торрент-клиента
            if (!$client->isOnline()) {
                $log->error("Торрент-клиент '{tag}' в данный момент недоступен", $logRecord);
                continue;
            }
        } catch (Exception $e) {
            $log->error(
                "Торрент-клиент '{tag}' в данный момент недоступен. {error}",
                [...$logRecord, 'error' => $e->getMessage()]
            );

            continue;
        }

        $response = false;
        switch ($actionType) {
            case 'set_label':
                $response = $client->setLabel($torrentHashes, $labelName);
                break;
            case 'stop':
                $response = $client->stopTorrents($torrentHashes);
                break;
            case 'start':
                $response = $client->startTorrents($torrentHashes, $forceStart);
                break;
            case 'remove':
                $response = $client->removeTorrents($torrentHashes, $removeFiles);
                if ($response !== false) {
                    // помечаем в базе удаление
                    Torrents::removeTorrents($torrentHashes);
                }
                break;
        }
        if ($response === false) {
            $log->warning(
                "Возникли проблемы при отправке запроса '{action}' для торрент-клиента '{tag}'",
                [...$logRecord, 'action' => $actionType]
            );
        } else {
            $log->info(
                "Действие '{action}' для торрент-клиента '{tag}' выполнено ({count})",
                [...$logRecord, 'action' => $actionType, 'count' => count($torrentHashes)]
            );
        }
        unset($clientID, $torrentHashes, $torrentClient);
    }
    $log->info("Выполнение действия '$actionType' завершено.");
    $log->info('-- DONE --');

    $result = "Действие '$actionType' выполнено. За подробностями обратитесь к журналу";
} catch (Exception $e) {
    $result = $e->getMessage();
    if (isset($log)) {
        $log->error($result);
    } else {
        Log::append($result);
    }
}

echo json_encode([
    'log'    => Log::get(),
    'result' => $result,
], JSON_UNESCAPED_UNICODE);
