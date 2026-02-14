<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\Config\SubFolderType;
use KeepersTeam\Webtlo\Config\SubForums;
use KeepersTeam\Webtlo\Config\TorrentClients;
use KeepersTeam\Webtlo\Config\TorrentDownload;
use KeepersTeam\Webtlo\Helper;

// Подключаем контейнер.
$app = App::create();
$log = $app->getLogger();

try {
    $result    = '';
    $starttime = microtime(true);

    // список ID раздач
    if (empty($_POST['topic_hashes'])) {
        throw new Exception('Выберите раздачи');
    }

    parse_str($_POST['topic_hashes'], $topicHashes);
    $topicHashes = Helper::convertKeysToString((array) $topicHashes['topic_hashes']);

    /** @var SubForums $subsections хранимые подразделы */
    $subsections = $app->get(SubForums::class);
    if (!$subsections->count()) {
        throw new Exception('В настройках не найдены хранимые подразделы');
    }

    /** @var TorrentClients $clients используемые торрент-клиенты */
    $clients = $app->get(TorrentClients::class);

    if (!$clients->count()) {
        throw new Exception('В настройках не найдены торрент-клиенты');
    }

    $db = $app->getDataBase();

    $forumClient = $app->getForumClient();
    if (!$forumClient->checkConnection()) {
        throw new RuntimeException('Ошибка подключения к форуму.');
    }

    /** @var TorrentDownload $downloadOptions */
    $downloadOptions = $app->get(TorrentDownload::class);

    /**
     * Ключи для скачивания файлов.
     *
     * @var ApiCredentials $apiCredentials
     */
    $apiCredentials = $app->get(ApiCredentials::class);

    // Записываем ключи доступа к API.
    $forumClient->setApiCredentials(apiCredentials: $apiCredentials);

    $log->info('Запущен процесс добавления раздач в торрент-клиенты...');
    // получение ID раздач с привязкой к подразделу
    $topicHashesByForums = [];

    $topicHashes = array_chunk($topicHashes, 999);
    foreach ($topicHashes as $topicHashesChunk) {
        $placeholders = str_repeat('?,', count($topicHashesChunk) - 1) . '?';

        $data = $db->query(
            'SELECT forum_id, info_hash FROM Topics WHERE info_hash IN (' . $placeholders . ')',
            $topicHashesChunk,
            PDO::FETCH_GROUP | PDO::FETCH_COLUMN,
        );
        unset($placeholders);

        foreach ($data as $forumID => $forumTopicHashes) {
            if (isset($topicHashesByForums[$forumID])) {
                $topicHashesByForums[$forumID] = array_merge(
                    $topicHashesByForums[$forumID],
                    $forumTopicHashes
                );
            } else {
                $topicHashesByForums[$forumID] = $forumTopicHashes;
            }
        }

        unset($topicHashesChunk, $forumTopicHashes, $data);
    }
    unset($topicHashes);

    if (empty($topicHashesByForums)) {
        $result = 'Не получены идентификаторы раздач с привязкой к подразделу';

        throw new Exception();
    }

    // полный путь до каталога для сохранения торрент-файлов
    $localPath = Helper::getStorageSubFolderPath(subFolder: 'tfiles');
    // очищаем каталог от старых торрент-файлов
    Helper::removeDirRecursive($localPath);
    // создаём каталог для торрент-файлов
    Helper::checkDirRecursive($localPath);

    // шаблон для сохранения
    $formatPathTorrentFile = Helper::normalizePathEncoding($localPath . DIRECTORY_SEPARATOR . '[webtlo].h%s.torrent');

    $clientFactory = $app->getClientFactory();

    $totalTorrentFilesAdded = 0;
    $usedTorrentClientsIDs  = [];
    foreach ($topicHashesByForums as $forumID => $topicHashes) {
        if (empty($topicHashes)) {
            continue;
        }

        $subForum = $subsections->getSubForum(subForumId: (int) $forumID);
        if ($subForum === null) {
            $log->warning('В настройках нет данных о подразделе с идентификатором "' . $forumID . '"');

            continue;
        }

        // идентификатор торрент-клиента
        $torrentClientId = $subForum->clientId;
        if (!$torrentClientId) {
            $log->warning('К подразделу "' . $forumID . '" не привязан торрент-клиент');

            continue;
        }

        // Подключаемся к торрент-клиенту
        $client = $clientFactory->getClientById(clientId: $torrentClientId);

        // Если клиент недоступен, пропускаем.
        if ($client === null) {
            continue;
        }

        $downloadedTorrentFiles = [];
        foreach ($topicHashes as $topicHash) {
            $topicHash = (string) $topicHash;

            $torrentFile = $forumClient->downloadTorrent(
                infoHash    : $topicHash,
                addRetracker: $downloadOptions->addRetracker,
            );
            if ($torrentFile === null) {
                $log->error('Не удалось скачать торрент-файл (' . $topicHash . ')');

                continue;
            }

            $torrentFile = $torrentFile->getContents();
            if (empty($torrentFile)) {
                continue;
            }

            // сохранить в каталог
            $response = file_put_contents(
                sprintf($formatPathTorrentFile, $topicHash),
                $torrentFile
            );
            if ($response === false) {
                $log->error('Произошла ошибка при сохранении торрент-файла (' . $topicHash . ')');

                continue;
            }

            $downloadedTorrentFiles[] = $topicHash;
        }

        $numberDownloadedTorrentFiles = count($downloadedTorrentFiles);
        if (!$numberDownloadedTorrentFiles) {
            $log->notice('Нет скачанных торрент-файлов для добавления их в торрент-клиент "' . $client->getClientTag() . '"');

            continue;
        }

        $clientAddingSleep = $client->getTorrentAddingSleep();

        // Убираем последний слэш в пути каталога для данных
        $dataFolder = trim($subForum->dataFolder);
        if (preg_match('/(\/|\\\)$/', $dataFolder)) {
            $dataFolder = substr($dataFolder, 0, -1);
        }

        // Определяем направление слэша в пути каталога для данных
        $delimiter = !str_contains($dataFolder, '/') ? '\\' : '/';

        // добавление раздач
        $downloadedTorrentFiles = array_chunk($downloadedTorrentFiles, 999);
        foreach ($downloadedTorrentFiles as $downloadedTorrentFilesChunk) {
            // получаем идентификаторы раздач
            $placeholders   = str_repeat('?,', count($downloadedTorrentFilesChunk) - 1) . '?';
            $topicIDsByHash = $db->query(
                'SELECT info_hash, id FROM Topics WHERE info_hash IN (' . $placeholders . ')',
                $downloadedTorrentFilesChunk,
                PDO::FETCH_KEY_PAIR
            );
            unset($placeholders);

            foreach ($downloadedTorrentFilesChunk as $topicHash) {
                $torrentSavePath = $dataFolder;

                // Дописываем подкаталог для сохранения.
                if ($subForum->subFolderType !== null) {
                    $subFolderPath = match ($subForum->subFolderType) {
                        SubFolderType::Topic => $topicIDsByHash[$topicHash],
                        SubFolderType::Hash  => $topicHash,
                    };

                    $torrentSavePath .= $delimiter . $subFolderPath;
                }

                // Добавляем раздачу в торрент-клиент.
                $response = $client->addTorrent(
                    torrentFilePath: sprintf($formatPathTorrentFile, $topicHash),
                    savePath       : $torrentSavePath,
                    label          : $subForum->label
                );
                if ($response !== false) {
                    $addedTorrentFiles[] = $topicHash;
                }

                // Пауза между добавлениями раздач, в зависимости от клиента (0.5 сек по умолчанию)
                usleep($clientAddingSleep);
            }
            unset($downloadedTorrentFilesChunk, $topicIDsByHash);
        }
        unset($downloadedTorrentFiles);

        if (empty($addedTorrentFiles)) {
            $log->warning('Не удалось добавить раздачи в торрент-клиент "' . $client->getClientTag() . '"');

            continue;
        }
        $numberAddedTorrentFiles = count($addedTorrentFiles);

        // Указываем раздачам метку, если она не выставлена при добавлении раздач.
        if ($subForum->label !== '' && !$client->isLabelAddingAllowed()) {
            // ждём добавления раздач, чтобы проставить метку
            sleep((int) round(count($addedTorrentFiles) / 20) + 1);

            // устанавливаем метку
            $response = $client->setLabel($addedTorrentFiles, $subForum->label);
            if ($response === false) {
                $log->warning('Возникли проблемы при отправке запроса на установку метки');
            }
        }

        // помечаем в базе добавленные раздачи
        $addedTorrentFilesChunks = array_chunk($addedTorrentFiles, 998);
        unset($addedTorrentFiles);

        foreach ($addedTorrentFilesChunks as $addedTorrentFilesChunk) {
            $placeholders = str_repeat('?,', count($addedTorrentFilesChunk) - 1) . '?';
            $db->query(
                'INSERT INTO Torrents (
                    info_hash,
                    client_id,
                    topic_id,
                    name,
                    total_size
                )
                SELECT
                    Topics.info_hash,
                    ?,
                    Topics.id,
                    Topics.name,
                    Topics.size
                FROM Topics
                WHERE info_hash IN (' . $placeholders . ')',
                array_merge([$torrentClientId], $addedTorrentFilesChunk)
            );

            unset($placeholders, $addedTorrentFilesChunk);
        }
        unset($addedTorrentFilesChunks);

        $log->info('Добавлено раздач в торрент-клиент "' . $client->getClientTag() . '": ' . $numberAddedTorrentFiles . ' шт.');

        $usedTorrentClientsIDs[] = $torrentClientId;
        $totalTorrentFilesAdded  += $numberAddedTorrentFiles;

        unset($client);
    }

    $totalTorrentClients = count(array_unique($usedTorrentClientsIDs));

    $result  = 'Задействовано торрент-клиентов — ' . $totalTorrentClients . ', добавлено раздач всего — ' . $totalTorrentFilesAdded . ' шт.';
    $endtime = microtime(true);

    $log->info('Процесс добавления раздач в торрент-клиенты завершён за ' . Helper::convertSeconds((int) ($endtime - $starttime)));

    $log->info($result);
} catch (Exception $e) {
    $result = $e->getMessage();
    if ($result) {
        $log->error($result);
    }
} finally {
    $log->info('-- DONE --');
}

echo json_encode([
    'log'    => $app->getLoggerRecords(),
    'result' => $result,
], JSON_UNESCAPED_UNICODE);
