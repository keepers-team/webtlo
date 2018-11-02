<?php

try {

    $starttime = microtime(true);

    include_once dirname(__FILE__) . '/../common.php';
    include_once dirname(__FILE__) . '/../classes/clients.php';
    include_once dirname(__FILE__) . '/../classes/download.php';

    $result = "";

    // проверка данных
    if (empty($_POST['topics_ids'])) {
        $result = "Выберите раздачи";
        throw new Exception();
    }

    if (empty($_POST['forums'])) {
        $result = "В настройках не найдены хранимые подразделы";
        throw new Exception();
    }

    if (empty($_POST['tor_clients'])) {
        $result = "В настройках не найдены торрент-клиенты";
        throw new Exception();
    }

    // парсим настройки
    if (isset($_POST['cfg'])) {
        parse_str($_POST['cfg'], $cfg);
    }

    if (empty($cfg['api_key'])) {
        $result = "В настройках не указан хранительский ключ API";
        throw new Exception();
    }

    if (empty($cfg['user_id'])) {
        $result = "В настройках не указан хранительский ключ ID";
        throw new Exception();
    }

    // разбираем настройки
    $forums = $_POST['forums'];
    $tor_clients = $_POST['tor_clients'];
    $cfg['retracker'] = isset($cfg['retracker']) ? 1 : 0;
    parse_str($_POST['topics_ids'], $topics_ids);

    Log::append("Запущен процесс добавления раздач в торрент-клиенты...");

    // получение ID раздач с привязкой к подразделу
    $forums_topics_ids = array();
    $topics_ids = array_chunk($topics_ids['topics_ids'], 999);
    foreach ($topics_ids as $topics_ids) {
        $in = str_repeat('?,', count($topics_ids) - 1) . '?';
        $forums_topics_ids += Db::query_database(
            "SELECT ss,id FROM Topics WHERE id IN ($in)",
            $topics_ids,
            true,
            PDO::FETCH_GROUP | PDO::FETCH_COLUMN
        );
        unset($in);
    }
    unset($topics_ids);

    if (empty($forums_topics_ids)) {
        $result = "Не получены идентификаторы раздач с привязкой к подразделу";
        throw new Exception();
    }

    // параметры прокси
    $activate_forum = isset($cfg['proxy_activate_forum']) ? 1 : 0;
    $activate_api = isset($cfg['proxy_activate_api']) ? 1 : 0;
    $proxy_address = $cfg['proxy_hostname'] . ':' . $cfg['proxy_port'];
    $proxy_auth = $cfg['proxy_login'] . ':' . $cfg['proxy_paswd'];

    // устанавливаем прокси
    Proxy::options(
        $activate_forum,
        $activate_api,
        $cfg['proxy_type'],
        $proxy_address,
        $proxy_auth
    );

    // каталог для сохранения торрент-файлов
    $torrent_files_dir = 'data/tfiles';

    // полный путь до каталога для сохранения торрент-файлов
    $torrent_files_path = dirname(__FILE__) . "/../../$torrent_files_dir";

    // очищаем каталог от старых торрент-файлов
    rmdir_recursive($torrent_files_path);

    // создаём каталог для торрент-файлов
    if (!mkdir_recursive($torrent_files_path)) {
        $result = 'Не удалось создать каталог "' . $torrent_files_path . '": неверно указан путь или недостаточно прав';
        throw new Exception();
    }

    $tor_clients_ids = array();
    $torrent_files_added_total = 0;

    foreach ($forums_topics_ids as $forum_id => $topics_ids) {

        if (empty($topics_ids)) {
            continue;
        }

        if (!isset($forums[$forum_id])) {
            Log::append("В настройках нет данных о подразделе с идентификатором \"$forum_id\"");
            continue;
        }

        // данные текущего подраздела
        $forum = $forums[$forum_id];

        if (empty($forum['cl'])) {
            Log::append("К подразделу \"$forum_id\" не привязан торрент-клиент");
            continue;
        }

        // идентификатор торрент-клиента
        $tor_client_id = $forum['cl'];

        if (empty($tor_clients[$tor_client_id])) {
            Log::append("В настройках нет данных о торрент-клиенте с идентификатором \"$tor_client_id\"");
            continue;
        }

        // данные текущего торрент-клиента
        $tor_client = $tor_clients[$tor_client_id];

        // шаблон для сохранения
        $torrent_files_path_pattern = "$torrent_files_path/[webtlo].t%s.torrent";
        if (PHP_OS == 'WINNT') {
            $torrent_files_path_pattern = mb_convert_encoding($torrent_files_path_pattern, 'Windows-1251', 'UTF-8');
        }

        // скачивание торрент-файлов
        $download = new Download(
            $cfg['forum_url'],
            $cfg['api_key'],
            $cfg['user_id']
        );

        foreach ($topics_ids as $topic_id) {
            $data = $download->get_torrent_file($topic_id, $cfg['retracker']);
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
                Log::append("Произошла ошибка при сохранении торрент-файла ($topic_id)");
                continue;
            }
            $torrent_files_downloaded[] = $topic_id;
        }

        if (empty($torrent_files_downloaded)) {
            Log::append('Нет скачанных торрент-файлов для добавления их в торрент-клиент "' . $tor_client['cm'] . '"');
            continue;
        }

        $count_downloaded = count($torrent_files_downloaded);

        // дополнительный слэш в конце каталога
        if (
            !empty($forum['fd'])
            && !in_array(substr($forum['fd'], -1), array('\\', '/'))
        ) {
            $forum['fd'] .= strpos($forum['fd'], '/') === false ? '\\' : '/';
        }

        // подключаемся к торрент-клиенту
        $client = new $tor_client['cl'](
            $tor_client['ht'],
            $tor_client['pt'],
            $tor_client['lg'],
            $tor_client['pw'],
            $tor_client['cm']
        );

        // проверяем доступность торрент-клиента
        if (!$client->is_online()) {
            Log::append('Error: торрент-клиент "' . $tor_client['cm'] . '" в данный момент недоступен');
            continue;
        }

        // формирование пути до файла на сервере
        $dirname_url = $_SERVER['SERVER_ADDR'] . str_replace(
            'php/', '', substr(
                $_SERVER['SCRIPT_NAME'], 0, strpos(
                    $_SERVER['SCRIPT_NAME'], '/', 1
                ) + 1
            )
        ) . $torrent_files_dir;

        $filename_url_pattern = "http://$dirname_url/[webtlo].t%s.torrent";

        // сохранение в подкаталог
        if ($forum['sub_folder']) {
            $forum['fd'] .= $topic_id;
        }

        // добавление раздач
        foreach ($torrent_files_downloaded as $topic_id) {
            $filename_url = sprintf(
                $filename_url_pattern,
                $topic_id
            );
            $client->torrentAdd($filename_url, $forum['fd']);
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
        Db::query_database(
            "CREATE TEMP TABLE Hashes AS
            SELECT hs FROM Clients WHERE 0 = 1"
        );

        // узнаём хэши раздач
        $torrent_files_added = array_chunk($torrent_files_added, 999);
        foreach ($torrent_files_added as $torrent_files_added) {
            $in = str_repeat('?,', count($torrent_files_added) - 1) . '?';
            Db::query_database(
                "INSERT INTO temp.Hashes
                SELECT hs FROM Topics WHERE id IN ($in)",
                $torrent_files_added
            );
            unset($in);
        }
        unset($torrent_files_added);

        // помечаем в базе добавленные раздачи
        Db::query_database(
            "INSERT INTO Clients (hs,cl,dl)
            SELECT hs,?,? FROM temp.Hashes",
            array($tor_client_id, 0)
        );

        if (!empty($forum['lb'])) {
            // вытаскиваем хэши добавленных раздач
            $topics_hashes = Db::query_database(
                "SELECT hs FROM temp.Hashes",
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

    $result = "Задействовано торрент-клиентов — $tor_clients_total, добавлено раздач всего — $torrent_files_added_total шт.";

    $endtime = microtime(true);

    Log::append("Процесс добавления раздач в торрент-клиенты завершён за " . convert_seconds($endtime - $starttime));

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
