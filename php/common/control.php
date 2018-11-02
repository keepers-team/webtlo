<?php

$starttime = microtime(true);

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/api.php';
include_once dirname(__FILE__) . '/../classes/clients.php';

Log::append("Начат процесс регулировки раздач в торрент-клиентах...");

// получение настроек
$cfg = get_settings();

// проверка настроек
if (empty($cfg['clients'])) {
    throw new Exception("Error: Не удалось получить список торрент-клиентов");
}

if (empty($cfg['subsections'])) {
    throw new Exception("Error: Не выбраны хранимые подразделы");
}

$forums_ids = array_keys($cfg['subsections']);
$ss = str_repeat('?,', count($forums_ids) - 1) . '?';

foreach ($cfg['clients'] as $client_id => $client_info) {

    $client = new $client_info['cl'](
        $client_info['ht'],
        $client_info['pt'],
        $client_info['lg'],
        $client_info['pw'],
        $client_info['cm']
    );

    if ($client->is_online()) {

        $torrents = $client->getTorrents();

        if (empty($torrents)) {
            Log::append('Warning: Не удалось получить данные о раздачах от торрент-клиента "' . $client_info['cm'] . '"');
            continue;
        }

        $count_torrents = count($torrents);

        // ограничение на количество хэшей за раз
        $torrents_hashes = array_chunk(
            array_keys($torrents),
            999 - count($forums_ids)
        );

        $topics_hashes = array();

        // вытаскиваем из базы хэши раздач только для хранимых подразделов
        foreach ($torrents_hashes as $torrents_hashes) {
            $hs = str_repeat('?,', count($torrents_hashes) - 1) . '?';
            $topics_hashes_query = Db::query_database(
                "SELECT hs FROM Topics WHERE hs IN ($hs) AND ss IN ($ss)",
                array_merge($torrents_hashes, $forums_ids),
                true,
                PDO::FETCH_COLUMN
            );
            $topics_hashes = array_merge($topics_hashes, $topics_hashes_query);
            unset($topics_hashes_query);
            unset($hs);
        }
        unset($torrents_hashes);

        // подключаемся к api
        if (!isset($api)) {
            $api = new Api($cfg['api_url'], $cfg['api_key']);
            Log::append("Получение данных о пирах...");
        }

        // получаем данные о пирах
        $topics_peer_stats = $api->get_peer_stats($topics_hashes, 'hash');
        unset($topics_hashes);

        foreach ($topics_peer_stats as $hash_info => $topic_data) {
            // если нет такой раздачи или идёт загрузка раздачи, идём дальше
            if (empty($torrents[$hash_info])) {
                continue;
            }
            // статус раздачи
            $tor_client_status = $torrents[$hash_info];
            // учитываем себя
            $topic_data['seeders'] -= $topic_data['seeders'] ? $tor_client_status : 0;
            // находим значение личей
            $leechers = $cfg['topics_control']['leechers'] ? $topic_data['leechers'] : 0;
            // находим значение пиров
            $peers = $topic_data['seeders'] + $leechers;
            // учитываем вновь прибывшего "лишнего" сида
            if (
                $topic_data['seeders']
                && $peers == $cfg['topics_control']['peers']
                && $tor_client_status == 1
            ) {
                $peers++;
            }

            // стопим только, если есть сиды
            if (
                $topic_data['seeders']
                && (
                    $peers > $cfg['topics_control']['peers']
                    || !$cfg['topics_control']['no_leechers']
                    && !$topic_data['leechers']
                )
            ) {
                if ($tor_client_status == 1) {
                    $control_hashes['stop'][] = $hash_info;
                }
            } else {
                if ($tor_client_status == -1) {
                    $control_hashes['start'][] = $hash_info;
                }
            }
        }
        unset($topic_peer_stats);

        if (empty($control_hashes)) {
            Log::append('Warning: Регулировка раздач не требуется для торрент-клиента "' . $client_info['cm'] . '"');
            continue;
        }

        // запускаем
        if (!empty($control_hashes['start'])) {
            $count_start = count($control_hashes['start']);
            $control_hashes['start'] = array_chunk($control_hashes['start'], 100);
            foreach ($control_hashes['start'] as $start) {
                $client->torrentStart($start);
            }
            Log::append('Запрос на запуск раздач торрент-клиенту "' . $client_info['cm'] . '" отправлен (' . $count_start . ')');
        }

        // останавливаем
        if (!empty($control_hashes['stop'])) {
            $count_stop = count($control_hashes['stop']);
            $control_hashes['stop'] = array_chunk($control_hashes['stop'], 100);
            foreach ($control_hashes['stop'] as $stop) {
                $client->torrentStop($stop);
            }
            Log::append('Запрос на остановку раздач торрент-клиенту "' . $client_info['cm'] . "\" отправлен ($count_stop)");
        }

        unset($control_hashes);

    }

}

$endtime = microtime(true);

Log::append("Регулировка раздач в торрент-клиентах завершена за " . convert_seconds($endtime - $starttime));
