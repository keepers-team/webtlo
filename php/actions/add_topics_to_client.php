<?php

try {
    $starttime = microtime(true);

    include_once dirname(__FILE__) . '/../common.php';
    include_once dirname(__FILE__) . '/../classes/clients.php';
    include_once dirname(__FILE__) . '/../classes/download.php';

    $result = '';

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
    $forums_topics_ids = array();
    $topicsIDs = array_chunk($topicsIDs['topics_ids'], 999);
    foreach ($topicsIDs as $topicsIDs) {
        $in = str_repeat('?,', count($topicsIDs) - 1) . '?';
        $forums_topics_ids_tmp = Db::query_database(
            'SELECT ss,id FROM Topics WHERE id IN (' . $in . ')',
            $topicsIDs,
            true,
            PDO::FETCH_GROUP | PDO::FETCH_COLUMN
        );
        unset($in);
        foreach ($forums_topics_ids_tmp as $forumID => $forums_topics_ids_tmp) {
            if (isset($forums_topics_ids[$forumID])) {
                $forums_topics_ids[$forumID] = array_merge(
                    $forums_topics_ids[$forumID],
                    $forums_topics_ids_tmp
                );
            } else {
                $forums_topics_ids[$forumID] = $forums_topics_ids_tmp;
            }
        }
        unset($forums_topics_ids_tmp);
    }
    unset($topicsIDs);

    if (empty($forums_topics_ids)) {
        $result = 'Не получены идентификаторы раздач с привязкой к подразделу';
        throw new Exception();
    }

    // каталог для сохранения торрент-файлов
    $directoryTorrentFiles = 'data/tfiles';

    // полный путь до каталога для сохранения торрент-файлов
    $localFullPath = dirname(__FILE__) . '/../../' . $directoryTorrentFiles;
    $localFullPath = normalizePath($localFullPath);

    // очищаем каталог от старых торрент-файлов
    rmdir_recursive($localFullPath);

    // создаём каталог для торрент-файлов
    if (!mkdir_recursive($localFullPath)) {
        $result = 'Не удалось создать каталог "' . $localFullPath . '": неверно указан путь или недостаточно прав';
        throw new Exception();
    }

    $usedTorrentClientsIDs = array();
    $torrent_files_added_total = 0;

    // скачивание торрент-файлов
    $download = new TorrentDownload($cfg['forum_address']);

    // применяем таймауты
    $download->setUserConnectionOptions($cfg['curl_setopt']['forum']);

    foreach ($forums_topics_ids as $forumID => $topicsIDs) {
        if (empty($topicsIDs)) {
            continue;
        }

        if (!isset($cfg['subsections'][$forumID])) {
            Log::append('В настройках нет данных о подразделе с идентификатором "' . $forumID . '"');
            continue;
        }

        // данные текущего подраздела
        $forum = $cfg['subsections'][$forumID];

        if (empty($forum['cl'])) {
            Log::append('К подразделу "' . $forumID . '" не привязан торрент-клиент');
            continue;
        }

        // идентификатор торрент-клиента
        $torrentClientID = $forum['cl'];

        if (empty($cfg['clients'][$torrentClientID])) {
            Log::append('В настройках нет данных о торрент-клиенте с идентификатором "' . $torrentClientID . '"');
            continue;
        }

        // данные текущего торрент-клиента
        $torrentClient = $cfg['clients'][$torrentClientID];

        // шаблон для сохранения
        $torrent_files_path_pattern = $localFullPath . '/[webtlo].t%s.torrent';
        if (PHP_OS == 'WINNT') {
            $torrent_files_path_pattern = mb_convert_encoding($torrent_files_path_pattern, 'Windows-1251', 'UTF-8');
        }

        foreach ($topicsIDs as $topicID) {
            $data = $download->getTorrentFile($cfg['api_key'], $cfg['user_id'], $topicID, $cfg['retracker']);
            if ($data === false) {
                continue;
            }
            // сохранить в каталог
            $file_put_contents = file_put_contents(
                sprintf(
                    $torrent_files_path_pattern,
                    $topicID
                ),
                $data
            );
            if ($file_put_contents === false) {
                Log::append('Произошла ошибка при сохранении торрент-файла (' . $topicID . ')');
                continue;
            }
            $torrent_files_downloaded[] = $topicID;
        }

        if (empty($torrent_files_downloaded)) {
            Log::append('Нет скачанных торрент-файлов для добавления их в торрент-клиент "' . $torrentClient['cm'] . '"');
            continue;
        }

        $count_downloaded = count($torrent_files_downloaded);

        // подключаемся к торрент-клиенту
        /**
         * @var utorrent|transmission|vuze|deluge|ktorrent|rtorrent|qbittorrent $client
         */
        $client = new $torrentClient['cl'](
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
        if (preg_match('/(\/|\\\\)$/', $forum['df'])) {
            $forum['df'] = substr($forum['df'], 0, -1);
        }

        // определяем направление слэша в пути каталога для данных
        $delimiter = strpos($forum['df'], '/') === false ? '\\' : '/';

        // добавление раздач
        foreach ($torrent_files_downloaded as $topicID) {
            $savePath = '';
            if (!empty($forum['df'])) {
                $savePath = $forum['df'];
                // подкаталог для данных
                if ($forum['sub_folder']) {
                    $savePath .= $delimiter . $topicID;
                }
            }
            // путь до торрент-файла на сервере
            $torrentFilePath = sprintf(
                $torrent_files_path_pattern,
                $topicID
            );
            $client->addTorrent($torrentFilePath, $savePath);
            $torrent_files_added[] = $topicID;
            // ждём полсекунды
            usleep(500000);
        }
        unset($torrent_files_downloaded);

        if (empty($torrent_files_added)) {
            Log::append('Нет удалось добавить раздачи в торрент-клиент "' . $torrentClient['cm'] . '"');
            continue;
        }

        $count_added = count($torrent_files_added);

        // создаём временную таблицу
        Db::query_database('DROP TABLE IF EXISTS temp.Hashes');
        Db::query_database(
            'CREATE TEMP TABLE Hashes AS
            SELECT hs FROM Clients WHERE 0 = 1'
        );

        // узнаём хэши раздач
        $torrent_files_added = array_chunk($torrent_files_added, 999);
        foreach ($torrent_files_added as $torrent_files_added) {
            $in = str_repeat('?,', count($torrent_files_added) - 1) . '?';
            Db::query_database(
                'INSERT INTO temp.Hashes
                SELECT hs FROM Topics WHERE id IN (' . $in . ')',
                $torrent_files_added
            );
            unset($in);
        }
        unset($torrent_files_added);

        // помечаем в базе добавленные раздачи
        Db::query_database(
            'INSERT INTO Clients (hs,cl,dl)
            SELECT hs,?,? FROM temp.Hashes',
            array($torrentClientID, 0)
        );

        if (!empty($forum['lb'])) {
            // вытаскиваем хэши добавленных раздач
            $topics_hashes = Db::query_database(
                'SELECT hs FROM temp.Hashes',
                array(),
                true,
                PDO::FETCH_COLUMN
            );
            // ждём добавления раздач, чтобы проставить метку
            sleep(round(count($topics_hashes) / 3) + 1); // < 3 дольше ожидание
            // устанавливаем метку
            $client->setLabel($topics_hashes, $forum['lb']);
            unset($topics_hashes);
        }

        Log::append('Добавлено раздач в торрент-клиент "' . $torrentClient['cm'] . '": ' . $count_added . ' шт.');

        if (!in_array($torrentClientID, $usedTorrentClientsIDs)) {
            $usedTorrentClientsIDs[] = $torrentClientID;
        }

        $torrent_files_added_total += $count_added;

        unset($torrentClient);
        unset($client);
        unset($forum);
    }

    $tor_clients_total = count($usedTorrentClientsIDs);

    $result = 'Задействовано торрент-клиентов — ' . $tor_clients_total . ', добавлено раздач всего — ' . $torrent_files_added_total . ' шт.';

    $endtime = microtime(true);

    Log::append('Процесс добавления раздач в торрент-клиенты завершён за ' . convert_seconds($endtime - $starttime));

    // выводим на экран
    echo json_encode(array(
        'log' => Log::get(),
        'result' => $result,
    ));
} catch (Exception $e) {
    Log::append($e->getMessage());
    echo json_encode(array(
        'log' => Log::get(),
        'result' => $result,
    ));
}
