<?php

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/clients.php';
include_once dirname(__FILE__) . '/../classes/api.php';

// получение настроек
if (!isset($cfg)) {
    $cfg = get_settings();
}

if (!empty($cfg['clients'])) {

    Log::append("Сканирование торрент-клиентов...");

    Log::append("Количество торрент-клиентов: " . count($cfg['clients']));

    // создаём временную таблицу
    Db::query_database(
        "CREATE TEMP TABLE ClientsNew AS
        SELECT hs,cl,dl FROM Clients WHERE 0 = 1"
    );

    foreach ($cfg['clients'] as $client_id => $client_info) {

        $client = new $client_info['cl'](
            $client_info['ht'],
            $client_info['pt'],
            $client_info['lg'],
            $client_info['pw'],
            $client_info['cm']
        );

        $count_torrents = 0;

        if ($client->is_online()) {

            $torrents = $client->getTorrents();

            if (empty($torrents)) {
                Log::append('Warning: Не удалось получить данные о раздачах от торрент-клиента "' . $client_info['cm'] . '"');
                continue;
            }

            $count_torrents = count($torrents);

            // array( 'hash' => 'tor_status' )
            // tor_status: 0 - загружается, 1 - раздаётся, -1 - на паузе или стопе
            foreach ($torrents as $hash_info => $tor_status) {
                $torrents_set[] = array(
                    'id' => $hash_info,
                    'cl' => $client_id,
                    'dl' => $tor_status,
                );
            }
            unset($torrents);

            $torrents_set = array_chunk($torrents_set, 500);

            foreach ($torrents_set as $torrents_set) {
                $select = Db::combine_set($torrents_set);
                Db::query_database("INSERT INTO temp.ClientsNew (hs,cl,dl) $select");
                unset($select);
            }
            unset($torrents_set);

        }

        Log::append($client_info['cm'] . ' (' . $client_info['cl'] . ") — получено раздач: $count_torrents шт.");

    }

    $count_clients = Db::query_database(
        "SELECT COUNT() FROM temp.ClientsNew",
        array(),
        true,
        PDO::FETCH_COLUMN
    );

    if ($count_clients[0] > 0) {

        Db::query_database(
            "INSERT INTO Clients (hs,cl,dl)
            SELECT * FROM temp.ClientsNew"
        );

    }

    Db::query_database(
        "DELETE FROM Clients WHERE hs NOT IN (
            SELECT Clients.hs FROM temp.ClientsNew LEFT JOIN Clients
            ON temp.ClientsNew.hs  = Clients.hs AND temp.ClientsNew.cl = Clients.cl
            WHERE Clients.hs IS NOT NULL
        )"
    );

    $untracked_hashes = Db::query_database(
        "SELECT Clients.hs FROM Clients
        LEFT JOIN Topics ON Topics.hs = Clients.hs
        WHERE Topics.id IS NULL AND Clients.dl IN (1,-1)",
        array(),
        true,
        PDO::FETCH_COLUMN
    );

    if (!empty($untracked_hashes)) {

        Log::append("Найдено сторонних раздач: " . count($untracked_hashes) . " шт.");

        // подключаемся к api
        if (!isset($api)) {
            $api = new Api($cfg['api_url'], $cfg['api_key']);
        }

        $untracked_ids = $api->get_topic_id($untracked_hashes);
        unset($untracked_hashes);

        if (!empty($untracked_ids)) {

            $untracked_data = $api->get_tor_topic_data($untracked_ids);
            unset($untracked_ids);

            if (empty($untracked_data)) {
                throw new Exception("Error: Не удалось получить данные о раздачах");
            }

            foreach ($untracked_data as $topic_id => $topic_data) {
                if (empty($topic_data)) {
                    continue;
                }
                $untracked_set[] = array(
                    'id' => $topic_id,
                    'ss' => $topic_data['forum_id'],
                    'na' => $topic_data['topic_title'],
                    'hs' => $topic_data['info_hash'],
                    'se' => $topic_data['seeders'],
                    'si' => $topic_data['size'],
                    'st' => $topic_data['tor_status'],
                    'rg' => $topic_data['reg_time'],
                );
            }
            unset($untracked_data);

            // создаём временную таблицу
            Db::query_database(
                "CREATE TEMP TABLE TopicsUntrackedNew AS
                SELECT id,ss,na,hs,se,si,st,rg FROM TopicsUntracked WHERE 0 = 1"
            );

            $untracked_set = array_chunk($untracked_set, 500);

            foreach ($untracked_set as $untracked_set) {
                $select = Db::combine_set($untracked_set);
                unset($untracked_set);
                Db::query_database("INSERT INTO temp.TopicsUntrackedNew $select");
                unset($select);
            }
            unset($untracked_set);

            $count_untracked = Db::query_database(
                "SELECT COUNT() FROM temp.TopicsUntrackedNew",
                array(),
                true,
                PDO::FETCH_COLUMN
            );

            if ($count_untracked[0] > 0) {
                Db::query_database(
                    "INSERT INTO TopicsUntracked (id,ss,na,hs,se,si,st,rg)
                    SELECT * FROM temp.TopicsUntrackedNew"
                );
            }

        }

    }

}
