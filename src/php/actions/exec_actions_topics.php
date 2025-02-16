<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\Storage\Table\Torrents;

try {
    $result = '';

    // разбираем данные
    $actionType  = $_POST['action'] ?? '';
    $labelName   = $_POST['label'] ?? '';
    $removeFiles = $_POST['remove_data'] ?? '';
    $forceStart  = (bool)($_POST['force_start'] ?? '');

    // Выбранный торрент клиент.
    $selectedClient = (int) ($_POST['sel_client'] ?? 0);

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

    $app = App::create();
    $db  = $app->getDataBase();

    // получение настроек
    $cfg = $app->getLegacyConfig();
    $log = $app->getLogger();

    $clientFactory = $app->getClientFactory();

    $log->info("Начато выполнение действия '$actionType' для выбранных раздач...");
    $log->debug('Получение хэшей раздач с привязкой к торрент-клиенту...');

    $torrentClients = $_POST['tor_clients'];
    parse_str($_POST['topic_hashes'], $topicHashes);

    /** @var Torrents $localTable Локальная БД хранения данных о раздачах. */
    $localTable = $app->get(Torrents::class);

    $topicHashes = Helper::convertKeysToString((array)$topicHashes['topic_hashes']);

    $torrentHashesByClient = $localTable->getGroupedByClientTopics(hashes: $topicHashes);

    unset($topicHashes);

    if (empty($torrentHashesByClient)) {
        throw new Exception('Не получены данные о выбранных раздачах');
    }

    $log->info('Количество затрагиваемых торрент-клиентов: {client}', ['client' => count($torrentHashesByClient)]);
    if ($actionType === 'remove' && $selectedClient === 0) {
        $log->notice('Не задан фильтр по торрент-клиенту. Выбранные раздачи будут удалены во всех торрент-клиентах.');
    }
    if ($selectedClient > 0) {
        $log->info('Задан фильтр по торрент-клиенту с идентификатором [{filter}].', ['filter' => $selectedClient]);
    }

    foreach ($torrentHashesByClient as $clientID => $torrentHashes) {
        if (empty($torrentHashes)) {
            continue;
        }
        if (empty($torrentClients[$clientID])) {
            $log->warning('В настройках нет данных о торрент-клиенте с идентификатором [{client}]', ['client' => $clientID]);
            continue;
        }

        // Пропускаем раздачи в других клиентах, если задан фильтр.
        if ($selectedClient > 0 && $selectedClient !== (int) $clientID) {
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
                $response = $client->setLabel(torrentHashes: $torrentHashes, label: $labelName);
                break;
            case 'stop':
                $response = $client->stopTorrents(torrentHashes: $torrentHashes);

                // Отмечаем в БД изменение статуса раздач.
                if ($response !== false) {
                    $localTable->setTorrentsStatusByHashes(hashes: $torrentHashes, paused: true);
                }
                break;
            case 'start':
                $response = $client->startTorrents(torrentHashes: $torrentHashes, forceStart: $forceStart);

                // Отмечаем в БД изменение статуса раздач.
                if ($response !== false) {
                    $localTable->setTorrentsStatusByHashes(hashes: $torrentHashes, paused: false);
                }
                break;
            case 'remove':
                $response = $client->removeTorrents(torrentHashes: $torrentHashes, deleteFiles: $removeFiles);

                // Отмечаем в БД удаление раздач.
                if ($response !== false) {
                    $localTable->deleteTorrentsByHashes(hashes: $torrentHashes);
                }
                break;
        }

        if ($response === false) {
            $log->warning(
                "Возникли проблемы при выполнении действия '{action}' для торрент-клиента '{tag}'",
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
