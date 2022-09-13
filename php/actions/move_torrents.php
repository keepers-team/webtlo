<?php
try {
    include dirname(__FILE__) . '/../common.php';
    include dirname(__FILE__) . '/../classes/clients.php';

    $starttime = microtime(true);
    $cfg = get_settings();
    $subsections = $cfg['subsections'];

    if (isset($_POST['forum_id'])) {
        $forum_id = $_POST['forum_id'];
    }

    if (
        !isset($forum_id)
        || !is_numeric($forum_id)
        || !isset($subsections[$forum_id])
    ) {
        throw new Exception('Некорректный идентификатор подраздела: ' . $forum_id);
    }

    if (empty($_POST['topics_ids'])) {
        throw new Exception('Выберите раздачи');
    }

    parse_str($_POST['topics_ids'], $topics_ids);

    // Перемещение доступно в этих клиентах
    $allowedClients = ['qbittorrent'];
    $moved = 0;

    $subsection = $cfg['subsections'][$forum_id];
    $clientNum = $subsection['cl'];
    $torrentClientData = $cfg['clients'][$clientNum];

    if (!in_array($torrentClientData['cl'], $allowedClients)) {
        throw new Exception('Перемещение данных в клиенте '.$torrentClientData['cl'].' недоступно.');
    }

    /**
     * * @var utorrent|transmission|vuze|deluge|ktorrent|rtorrent|qbittorrent $client
     * */
    $client = new $torrentClientData['cl'](
        $torrentClientData['ssl'],
        $torrentClientData['ht'],
        $torrentClientData['pt'],
        $torrentClientData['lg'],
        $torrentClientData['pw']
    );

    // проверка доступности торрент-клиента
    if ($client->isOnline() === false) {
        throw new Exception('Не подключения к торрент-клиенту');
    }
    // применяем таймауты
    $client->setUserConnectionOptions($cfg['curl_setopt']['torrent_client']);

    // Список ид раздач делим на пачки и ищем в БД хеши.
    $topics_ids = array_chunk($topics_ids['topics_ids'], 100);
    foreach ($topics_ids as $topics_ids) {
        $placeholders = str_repeat('?,', count($topics_ids) - 1) . '?';
        $torrentHashes = Db::query_database(
            'SELECT DISTINCT hs FROM Topics WHERE id IN (' . $placeholders . ')',
            $topics_ids,
            true,
            PDO::FETCH_COLUMN
        );

        // получение данных от торрент-клиента
        // $params = array('category' => $subsection['lb'], 'limit' => 200);
        $params = array('hashes' => implode('|', $torrentHashes));
        $torrents = $client->getAllTorrents($params);

        if ($torrents === false) {
            throw new Exception(
                'Error: Не удалось получить данные о раздачах '.
                'от торрент-клиента "' . $torrentClientData['cm'] . '"'
            );
        }
        Log::append(
            'Получение данных о торрентах (' . count($torrents) . ' шт.)'.
            ' завершено за ' . convert_seconds(microtime(true) - $starttime)
        );
        $starttime = microtime(true);

        // Анализируем полученные от клиента записи на предмет необходимости переноса
        $topicsMove = array();
        foreach ($torrents as $torrentHash => $torrentData) {
            // Вычленяем верный ид раздачи из комментария. И текущий ид, из папки, если вдруг есть такой.
            $topicID    = get_torrent_topic_id($torrentData);
            $topicOldID = 0;

            $splitLocation = preg_split('|[/\\\]+|', $torrentData['location']);
            if (count($splitLocation) && is_numeric(end($splitLocation))) {
                $topicOldID = end($splitLocation);
            }

            $location = prepareSubsectionPath($subsection, $topicID);

            $topicNeedMoving = false;
            if (!$topicOldID || $topicOldID !== $topicID) {
                $topicNeedMoving = true;
            }
            if ($location && $location != $torrentData['location']) {
                $topicNeedMoving = true;
            }
            if ($topicNeedMoving) {
                $topicsMove[$topicID] = array(
                    'torrentName'  => $torrentData['name'],
                    'torrentHash'  => $torrentHash,
                    'topicOldID'   => $topicOldID,
                    'locationOld'  => $torrentData['location'],
                    'categoryPath' => $subsection['df'],
                    'location'     => $location
                );
            }
            unset($torrentData, $torrentHash, $topicID, $topicOldID);
        }
        unset($torrents);
        $starttime = microtime(true);

        // Отправляем торрент-клиенту команду на перенос
        $topicsMove = array_chunk($topicsMove, 3, true);
        foreach ($topicsMove as $topicsMove) {
            foreach ($topicsMove as $topicID => $torrent) {
                $client->moveTorrent($torrent['torrentHash'], $torrent['location']);
                $moved++;
                Log::append(
                    $topicID . ', ' . $torrent['torrentHash'] .
                    ', ' . $torrent['torrentName'] .
                    ' moved ' . $torrent['locationOld'] . ' => ' . $torrent['location']
                );
            }
            // ждём полсекунды
            usleep(500000);
        }
    }

    Log::append(
        'Отправка команды о перемещении раздач завершено за ' .
        convert_seconds(microtime(true) - $starttime)
    );
    echo json_encode(array(
        'log' => Log::get(),
        'result' => 'Перемещено '.$moved.' раздач.',
    ));


} catch (Exception $e) {
    Log::append($e->getMessage());
    $result = 'В процессе перемещения раздач были ошибки. ' .
        'Для получения подробностей обратитесь к журналу событий.';
    echo json_encode(array(
        'log' => Log::get(),
        'result' => $result,
    ));
}


