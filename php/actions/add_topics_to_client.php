<?php

try {
    $result = '';
    $starttime = microtime(true);
    include_once dirname(__FILE__) . '/../common.php';
    include_once dirname(__FILE__) . '/../classes/clients.php';
    include_once dirname(__FILE__) . '/../classes/download.php';
    // список ID раздач
    if (empty($_POST['topics_ids'])) {
        $result = 'Выберите раздачи';
        throw new Exception();
    }
    parse_str($_POST['topics_ids'], $topicsIDs);
    // получение настроек
    $cfg = get_settings();
    if (empty($cfg['subsections'])) {
        $result = 'В настройках не найдены хранимые подразделы';
        throw new Exception();
    }
    if (empty($cfg['clients'])) {
        $result = 'В настройках не найдены торрент-клиенты';
        throw new Exception();
    }
    if (empty($cfg['api_key'])) {
        $result = 'В настройках не указан хранительский ключ API';
        throw new Exception();
    }
    if (empty($cfg['user_id'])) {
        $result = 'В настройках не указан хранительский ключ ID';
        throw new Exception();
    }
    Log::append('Запущен процесс добавления раздач в торрент-клиенты...');
    // получение ID раздач с привязкой к подразделу
    $topicsIDsForums = array();
    $topicsIDs = array_chunk($topicsIDs['topics_ids'], 999);
    foreach ($topicsIDs as $topicsIDs) {
        $placeholders = str_repeat('?,', count($topicsIDs) - 1) . '?';
        $response = Db::query_database(
            'SELECT ss,id FROM Topics WHERE id IN (' . $placeholders . ')',
            $topicsIDs,
            true,
            PDO::FETCH_GROUP | PDO::FETCH_COLUMN
        );
        unset($placeholders);
        foreach ($response as $forumID => $topicsIDsForum) {
            if (isset($topicsIDsForums[$forumID])) {
                $topicsIDsForums[$forumID] = array_merge(
                    $topicsIDsForums[$forumID],
                    $topicsIDsForum
                );
            } else {
                $topicsIDsForums[$forumID] = $topicsIDsForum;
            }
        }
        unset($topicsIDsForum);
        unset($response);
    }
    unset($topicsIDs);
    if (empty($topicsIDsForums)) {
        $result = 'Не получены идентификаторы раздач с привязкой к подразделу';
        throw new Exception();
    }
    // каталог для сохранения торрент-файлов
    $directoryTorrentFiles = 'data/tfiles';
    // полный путь до каталога для сохранения торрент-файлов
    $localPath = dirname(__FILE__) . '/../../' . $directoryTorrentFiles;
    $localPath = normalizePath($localPath);
    // очищаем каталог от старых торрент-файлов
    rmdir_recursive($localPath);
    // создаём каталог для торрент-файлов
    if (!mkdir_recursive($localPath)) {
        $result = 'Не удалось создать каталог "' . $localPath . '": неверно указан путь или недостаточно прав';
        throw new Exception();
    }
    $totalTorrentFilesAdded = 0;
    $usedTorrentClientsIDs = array();
    // скачивание торрент-файлов
    $download = new TorrentDownload($cfg['forum_address']);
    // применяем таймауты
    $download->setUserConnectionOptions($cfg['curl_setopt']['forum']);
    foreach ($topicsIDsForums as $forumID => $topicsIDs) {
        if (empty($topicsIDs)) {
            continue;
        }
        if (!isset($cfg['subsections'][$forumID])) {
            Log::append('В настройках нет данных о подразделе с идентификатором "' . $forumID . '"');
            continue;
        }
        // данные текущего подраздела
        $forumData = $cfg['subsections'][$forumID];

        if (empty($forumData['cl'])) {
            Log::append('К подразделу "' . $forumID . '" не привязан торрент-клиент');
            continue;
        }
        // идентификатор торрент-клиента
        $torrentClientID = $forumData['cl'];
        if (empty($cfg['clients'][$torrentClientID])) {
            Log::append('В настройках нет данных о торрент-клиенте с идентификатором "' . $torrentClientID . '"');
            continue;
        }
        // данные текущего торрент-клиента
        $torrentClient = $cfg['clients'][$torrentClientID];
        // шаблон для сохранения
        $formatPathTorrentFile = $localPath . '/[webtlo].t%s.torrent';
        if (PHP_OS == 'WINNT') {
            $formatPathTorrentFile = mb_convert_encoding($formatPathTorrentFile, 'Windows-1251', 'UTF-8');
        }
        foreach ($topicsIDs as $topicID) {
            $torrentFile = $download->getTorrentFile($cfg['api_key'], $cfg['user_id'], $topicID, $cfg['retracker']);
            if ($torrentFile === false) {
                Log::append('Error: Не удалось скачать торрент-файл (' . $topicID . ')');
                continue;
            }
            // сохранить в каталог
            $response = file_put_contents(
                sprintf($formatPathTorrentFile, $topicID),
                $torrentFile
            );
            if ($response === false) {
                Log::append('Error: Произошла ошибка при сохранении торрент-файла (' . $topicID . ')');
                continue;
            }
            $downloadedTorrentFiles[] = $topicID;
        }
        if (empty($downloadedTorrentFiles)) {
            Log::append('Нет скачанных торрент-файлов для добавления их в торрент-клиент "' . $torrentClient['cm'] . '"');
            continue;
        }
        $numberDownloadedTorrentFiles = count($downloadedTorrentFiles);
        // подключаемся к торрент-клиенту
        /**
         * @var utorrent|transmission|vuze|deluge|ktorrent|rtorrent|qbittorrent $client
         */
        $client = new $torrentClient['cl'](
            $torrentClient['ssl'],
            $torrentClient['ht'],
            $torrentClient['pt'],
            $torrentClient['lg'],
            $torrentClient['pw']
        );
        // проверяем доступность торрент-клиента
        if (!$client->isOnline()) {
            Log::append('Error: торрент-клиент "' . $torrentClient['cm'] . '" в данный момент недоступен');
            continue;
        }
        // убираем последний слэш в пути каталога для данных
        if (preg_match('/(\/|\\\\)$/', $forumData['df'])) {
            $forumData['df'] = substr($forumData['df'], 0, -1);
        }
        // определяем направление слэша в пути каталога для данных
        $delimiter = strpos($forumData['df'], '/') === false ? '\\' : '/';
        // добавление раздач
        foreach ($downloadedTorrentFiles as $topicID) {
            $savePath = '';
            if (!empty($forumData['df'])) {
                $savePath = $forumData['df'];
                // подкаталог для данных
                if ($forumData['sub_folder']) {
                    $savePath .= $delimiter . $topicID;
                }
            }
            // путь до торрент-файла на сервере
            $torrentFilePath = sprintf($formatPathTorrentFile, $topicID);
            $response = $client->addTorrent($torrentFilePath, $savePath);
            if ($response !== false) {
                $addedTorrentFiles[] = $topicID;
            }
            // ждём полсекунды
            usleep(500000);
        }
        unset($downloadedTorrentFiles);
        if (empty($addedTorrentFiles)) {
            Log::append('Не удалось добавить раздачи в торрент-клиент "' . $torrentClient['cm'] . '"');
            continue;
        }
        $numberAddedTorrentFiles = count($addedTorrentFiles);
        // создаём временную таблицу
        Db::query_database('DROP TABLE IF EXISTS temp.Hashes');
        Db::query_database(
            'CREATE TEMP TABLE Hashes AS
            SELECT hs FROM Clients WHERE 0 = 1'
        );
        // узнаём хэши раздач
        $addedTorrentFiles = array_chunk($addedTorrentFiles, 999);
        foreach ($addedTorrentFiles as $addedTorrentFiles) {
            $placeholders = str_repeat('?,', count($addedTorrentFiles) - 1) . '?';
            Db::query_database(
                'INSERT INTO temp.Hashes
                SELECT hs FROM Topics WHERE id IN (' . $placeholders . ')',
                $addedTorrentFiles
            );
            unset($placeholders);
        }
        unset($addedTorrentFiles);
        // помечаем в базе добавленные раздачи
        Db::query_database(
            'INSERT INTO Clients (hs,cl,dl)
            SELECT hs,?,? FROM temp.Hashes',
            array($torrentClientID, 0)
        );
        if (!empty($forumData['lb'])) {
            // вытаскиваем хэши добавленных раздач
            $topicsHashes = Db::query_database(
                'SELECT hs FROM temp.Hashes',
                array(),
                true,
                PDO::FETCH_COLUMN
            );
            // ждём добавления раздач, чтобы проставить метку
            sleep(round(count($topicsHashes) / 3) + 1); // < 3 дольше ожидание
            // устанавливаем метку
            $response = $client->setLabel($topicsHashes, $forumData['lb']);
            if ($response === false) {
                Log::append('Error: Возникли проблемы при отправке запроса на установку метки');
            }
            unset($topicsHashes);
        }
        Log::append('Добавлено раздач в торрент-клиент "' . $torrentClient['cm'] . '": ' . $numberAddedTorrentFiles . ' шт.');
        if (!in_array($torrentClientID, $usedTorrentClientsIDs)) {
            $usedTorrentClientsIDs[] = $torrentClientID;
        }
        $totalTorrentFilesAdded += $numberAddedTorrentFiles;
        unset($torrentClient);
        unset($forumData);
        unset($client);
    }
    $totalTorrentClients = count($usedTorrentClientsIDs);
    $result = 'Задействовано торрент-клиентов — ' . $totalTorrentClients . ', добавлено раздач всего — ' . $totalTorrentFilesAdded . ' шт.';
    $endtime = microtime(true);
    Log::append('Процесс добавления раздач в торрент-клиенты завершён за ' . convert_seconds($endtime - $starttime));
    // выводим на экран
    echo json_encode(
        array(
            'log' => Log::get(),
            'result' => $result,
        )
    );
} catch (Exception $e) {
    Log::append($e->getMessage());
    echo json_encode(
        array(
            'log' => Log::get(),
            'result' => $result,
        )
    );
}
