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
    parse_str($_POST['topics_ids'], $topics_ids);

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

    // список торрент-клиентов, которые поддерживают передачу содержимого торрент-файла в запросе
    $raw_torrent_data_support_list = array('qbittorrent', 'transmission', 'vuze', 'rtorrent');

    Log::append('Запущен процесс добавления раздач в торрент-клиенты...');

    // получение ID раздач с привязкой к подразделу
    $forums_topics_ids = array();
    $topics_ids = array_chunk($topics_ids['topics_ids'], 999);
    foreach ($topics_ids as $topics_ids) {
        $in = str_repeat('?,', count($topics_ids) - 1) . '?';
        $forums_topics_ids_tmp = Db::query_database(
            'SELECT ss,id FROM Topics WHERE id IN (' . $in . ')',
            $topics_ids,
            true,
            PDO::FETCH_GROUP | PDO::FETCH_COLUMN
        );
        unset($in);
        foreach ($forums_topics_ids_tmp as $forum_id => $forums_topics_ids_tmp) {
            if (isset($forums_topics_ids[$forum_id])) {
                $forums_topics_ids[$forum_id] = array_merge(
                    $forums_topics_ids[$forum_id],
                    $forums_topics_ids_tmp
                );
            } else {
                $forums_topics_ids[$forum_id] = $forums_topics_ids_tmp;
            }
        }
        unset($forums_topics_ids_tmp);
    }
    unset($topics_ids);

    if (empty($forums_topics_ids)) {
        $result = 'Не получены идентификаторы раздач с привязкой к подразделу';
        throw new Exception();
    }

    // каталог для сохранения торрент-файлов
    $torrent_files_dir = 'data/tfiles';

    // полный путь до каталога для сохранения торрент-файлов
    $torrent_files_path = dirname(__FILE__) . '/../../' . $torrent_files_dir;

    // очищаем каталог от старых торрент-файлов
    rmdir_recursive($torrent_files_path);

    // создаём каталог для торрент-файлов
    if (!mkdir_recursive($torrent_files_path)) {
        $result = 'Не удалось создать каталог "' . $torrent_files_path . '": неверно указан путь или недостаточно прав';
        throw new Exception();
    }

    $tor_clients_ids = array();
    $torrent_files_added_total = 0;

    // скачивание торрент-файлов
    $download = new TorrentDownload($cfg['forum_url']);

    // применяем таймауты
    $download->setUserConnectionOptions($cfg['curl_setopt']['forum']);

    foreach ($forums_topics_ids as $forum_id => $topics_ids) {
        if (empty($topics_ids)) {
            continue;
        }

        if (!isset($cfg['subsections'][$forum_id])) {
            Log::append('В настройках нет данных о подразделе с идентификатором "' . $forum_id . '"');
            continue;
        }

        // данные текущего подраздела
        $forum = $cfg['subsections'][$forum_id];

        if (empty($forum['cl'])) {
            Log::append('К подразделу "' . $forum_id . '" не привязан торрент-клиент');
            continue;
        }

        // идентификатор торрент-клиента
        $tor_client_id = $forum['cl'];

        if (empty($cfg['clients'][$tor_client_id])) {
            Log::append('В настройках нет данных о торрент-клиенте с идентификатором "' . $tor_client_id . '"');
            continue;
        }

        // данные текущего торрент-клиента
        $tor_client = $cfg['clients'][$tor_client_id];

        // шаблон для сохранения
        $torrent_files_path_pattern = $torrent_files_path . '/[webtlo].t%s.torrent';
        if (PHP_OS == 'WINNT') {
            $torrent_files_path_pattern = mb_convert_encoding($torrent_files_path_pattern, 'Windows-1251', 'UTF-8');
        }

        foreach ($topics_ids as $topic_id) {
            $data = $download->getTorrentFile($cfg['api_key'], $cfg['user_id'], $topic_id, $cfg['retracker']);
            if ($data === false) {
                continue;
            }
            // сохранить в каталог
            $file_put_contents = file_put_contents(
                sprintf(
                    $torrent_files_path_pattern,
                    $topic_id
                ),
                $data
            );
            if ($file_put_contents === false) {
                Log::append('Произошла ошибка при сохранении торрент-файла (' . $topic_id . ')');
                continue;
            }
            $torrent_files_downloaded[] = $topic_id;
        }

        if (empty($torrent_files_downloaded)) {
            Log::append('Нет скачанных торрент-файлов для добавления их в торрент-клиент "' . $tor_client['cm'] . '"');
            continue;
        }

        $count_downloaded = count($torrent_files_downloaded);

        // подключаемся к торрент-клиенту
        /**
         * @var utorrent|transmission|vuze|deluge|ktorrent|rtorrent|qbittorrent $client
         */
        $client = new $tor_client['cl'](
            $tor_client['ht'],
            $tor_client['pt'],
            $tor_client['lg'],
            $tor_client['pw']
        );

        // проверяем доступность торрент-клиента
        if (!$client->isOnline()) {
            Log::append('Error: торрент-клиент "' . $tor_client['cm'] . '" в данный момент недоступен');
            continue;
        }

        // формирование пути до файла на сервере
        $dirname_url = $_SERVER['SERVER_ADDR'] . ':' . $_SERVER['SERVER_PORT'] . str_replace(
            'php/',
            '',
            substr(
                $_SERVER['SCRIPT_NAME'],
                0,
                strpos(
                    $_SERVER['SCRIPT_NAME'],
                    '/',
                    1
                ) + 1
            )
        ) . $torrent_files_dir;

        $filename_url_pattern = 'http://' . $dirname_url . '/[webtlo].t%s.torrent';

        // убираем последний слэш в пути каталога для данных
        if (preg_match('/(\/|\\\\)$/', $forum['df'])) {
            $forum['df'] = substr($forum['df'], 0, -1);
        }

        // определяем направление слэша в пути каталога для данных
        $slash = strpos($forum['df'], '/') === false ? '\\' : '/';

        // добавление раздач
        foreach ($torrent_files_downloaded as $topic_id) {
            $save_path = '';
            if (!empty($forum['df'])) {
                $save_path = $forum['df'];
                // подкаталог для данных
                if ($forum['sub_folder']) {
                    $save_path .= $slash . $topic_id;
                }
            }
            // путь до торрент-файла на сервере
            $raw_torrent_data_support = in_array($tor_client['cl'], $raw_torrent_data_support_list);
            if ($raw_torrent_data_support) {
                $filename_url = sprintf(
                    $torrent_files_path_pattern,
                    $topic_id
                );
            } else {
                $filename_url = sprintf(
                    $filename_url_pattern,
                    $topic_id
                );
            }
            $client->addTorrent($filename_url, $save_path);
            $torrent_files_added[] = $topic_id;
            // ждём полсекунды
            usleep(500000);
        }
        unset($torrent_files_downloaded);

        if (empty($torrent_files_added)) {
            Log::append('Нет удалось добавить раздачи в торрент-клиент "' . $tor_client['cm'] . '"');
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
            array($tor_client_id, 0)
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

        Log::append('Добавлено раздач в торрент-клиент "' . $tor_client['cm'] . '": ' . $count_added . ' шт.');

        if (!in_array($tor_client_id, $tor_clients_ids)) {
            $tor_clients_ids[] = $tor_client_id;
        }

        $torrent_files_added_total += $count_added;

        unset($tor_client);
        unset($client);
        unset($forum);
    }

    $tor_clients_total = count($tor_clients_ids);

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
