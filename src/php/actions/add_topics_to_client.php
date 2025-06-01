<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\Helper;

// Подключаем контейнер.
$app = App::create();
$log = $app->getLogger();

try {
    $result    = '';
    $starttime = microtime(true);

    // список ID раздач
    if (empty($_POST['topic_hashes'])) {
        $result = 'Выберите раздачи';

        throw new Exception();
    }
    parse_str($_POST['topic_hashes'], $topicHashes);
    $topicHashes = Helper::convertKeysToString((array) $topicHashes['topic_hashes']);

    $db = $app->getDataBase();

    // получение настроек
    $cfg = $app->getLegacyConfig();

    if (empty($cfg['subsections'])) {
        $result = 'В настройках не найдены хранимые подразделы';

        throw new Exception();
    }
    if (empty($cfg['clients'])) {
        $result = 'В настройках не найдены торрент-клиенты';

        throw new Exception();
    }

    $forumClient = $app->getForumClient();
    if (!$forumClient->checkConnection()) {
        throw new RuntimeException('Ошибка подключения к форуму.');
    }

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

    $addRetracker = (bool) ($cfg['retracker'] ?? false);

    $totalTorrentFilesAdded = 0;
    $usedTorrentClientsIDs  = [];
    foreach ($topicHashesByForums as $forumID => $topicHashes) {
        if (empty($topicHashes)) {
            continue;
        }
        if (!isset($cfg['subsections'][$forumID])) {
            $log->warning('В настройках нет данных о подразделе с идентификатором "' . $forumID . '"');

            continue;
        }
        // данные текущего подраздела
        $forumData = $cfg['subsections'][$forumID];

        if (empty($forumData['cl'])) {
            $log->warning('К подразделу "' . $forumID . '" не привязан торрент-клиент');

            continue;
        }

        // идентификатор торрент-клиента
        $torrentClientId = (int) $forumData['cl'];

        // Подключаемся к торрент-клиенту
        $client = $clientFactory->getClientById(clientId: $torrentClientId);

        // Если клиент недоступен, пропускаем.
        if ($client === null) {
            continue;
        }

        foreach ($topicHashes as $topicHash) {
            $topicHash = (string) $topicHash;

            $torrentFile = $forumClient->downloadTorrent(infoHash: $topicHash, addRetracker: $addRetracker);
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

        if (empty($downloadedTorrentFiles)) {
            $log->notice('Нет скачанных торрент-файлов для добавления их в торрент-клиент "' . $client->getClientTag() . '"');

            continue;
        }

        $numberDownloadedTorrentFiles = count($downloadedTorrentFiles);

        $clientAddingSleep = $client->getTorrentAddingSleep();

        // убираем последний слэш в пути каталога для данных
        if (preg_match('/(\/|\\\)$/', $forumData['df'])) {
            $forumData['df'] = substr($forumData['df'], 0, -1);
        }
        // определяем направление слэша в пути каталога для данных
        $delimiter = !str_contains($forumData['df'], '/') ? '\\' : '/';

        $forumLabel = $forumData['lb'] ?? '';
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
                $savePath = '';
                if (!empty($forumData['df'])) {
                    $savePath = $forumData['df'];
                    // подкаталог для данных
                    if ($forumData['sub_folder']) {
                        if ($forumData['sub_folder'] == 1) {
                            $subdirectory = $topicIDsByHash[$topicHash];
                        } elseif ($forumData['sub_folder'] == 2) {
                            $subdirectory = $topicHash;
                        } else {
                            $subdirectory = '';
                        }
                        $savePath .= $delimiter . $subdirectory;
                    }
                }
                // путь до торрент-файла на сервере
                $torrentFilePath = sprintf($formatPathTorrentFile, $topicHash);

                // Добавляем раздачу в торрент-клиент.
                $response = $client->addTorrent($torrentFilePath, $savePath, $forumLabel);
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
        if ($forumLabel !== '' && !$client->isLabelAddingAllowed()) {
            // ждём добавления раздач, чтобы проставить метку
            sleep((int) round(count($addedTorrentFiles) / 20) + 1);

            // устанавливаем метку
            $response = $client->setLabel($addedTorrentFiles, $forumLabel);
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

        unset($forumData, $client);
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
