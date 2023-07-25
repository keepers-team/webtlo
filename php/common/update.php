<?php

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/api.php';

Timers::start('full_update');

// обновляем дерево подразделов
include_once dirname(__FILE__) . '/forum_tree.php';

// обновляем список высокоприоритетных раздач
include_once dirname(__FILE__) . '/high_priority_topics.php';

// получение настроек
if (!isset($cfg)) {
    $cfg = get_settings();
}

if (isset($checkEnabledCronAction)) {
    $checkEnabledCronAction = $cfg['automation'][$checkEnabledCronAction] ?? -1;
    if ($checkEnabledCronAction == 0) {
        throw new Exception('Notice: Автоматическое обновление сведений о раздачах в хранимых подразделах отключено в настройках.');
    }
}


// подключаемся к api
if (!isset($api)) {
    $api = new Api($cfg['api_address'], $cfg['api_key']);
    // применяем таймауты
    $api->setUserConnectionOptions($cfg['curl_setopt']['api']);
}

Timers::start('topics_update');
Log::append("Начато обновление сведений о раздачах в хранимых подразделах...");
// создаём временные таблицы
Db::query_database(
    "CREATE TEMP TABLE UpdateTimeNow AS
    SELECT id,ud FROM UpdateTime WHERE 0 = 1"
);
Db::query_database(
    "CREATE TEMP TABLE TopicsUpdate AS
    SELECT id,ss,se,st,qt,ds,pt,ls FROM Topics WHERE 0 = 1"
);
Db::query_database(
    "CREATE TEMP TABLE TopicsRenew AS
    SELECT id,ss,na,hs,se,si,st,rg,qt,ds,pt,ps,ls FROM Topics WHERE 0 = 1"
);

Db::query_database(
    "CREATE TEMP TABLE KeepersSeedersNew AS
    SELECT * FROM KeepersSeeders WHERE 0 = 1"
);

// время текущего и предыдущего обновления
$currentUpdateTime  = new DateTime();
$previousUpdateTime = new DateTime();

$forumsUpdateTime = [];
if (isset($cfg['subsections'])) {
    // все открытые раздачи
    $allowedTorrentStatuses = [0, 2, 3, 8, 10];
    // получим список всех хранителей
    $keepersUserData = $api->getKeepersUserData();
    $keepersUserData = $keepersUserData['result'] ?? [];

    // обновим каждый хранимый подраздел
    foreach ($cfg['subsections'] as $forum_id => $subsection) {
        // получаем дату предыдущего обновления
        $update_time = get_last_update_time($forum_id);

        // если не прошёл час
        if (time() - $update_time < 3600) {
            Log::append("Notice: Не требуется обновление для подраздела № " . $forum_id);
            continue;
        }

        Timers::start("update_forum_$forum_id");
        // получаем данные о раздачах
        $topics_data = $api->getForumTopicsData($forum_id);
        if (empty($topics_data['result'])) {
            Log::append("Error: Не получены данные о подразделе № " . $forum_id);
            continue;
        }

        // количество и вес раздач
        $topics_count = count($topics_data['result']);
        $topics_size  = $topics_data['total_size_bytes'];
        $topic_keys   = $topics_data['format']['topic_id'];

        // запоминаем время обновления каждого подраздела
        $forumsUpdateTime[$forum_id]['ud'] = $topics_data['update_time'];

        // текущее обновление в DateTime
        $currentUpdateTime->setTimestamp($topics_data['update_time']);

        // предыдущее обновление в DateTime
        $previousUpdateTime->setTimestamp($update_time)->setTime(0, 0);

        // разница в днях между обновлениями сведений
        $daysDiffAdjusted = $currentUpdateTime->diff($previousUpdateTime)->format('%d');

        // разбиваем result по 500 раздач
        $topics_chunks = array_chunk($topics_data['result'], 500, true);
        unset($topics_data);

        foreach ($topics_chunks as $topics_result) {
            // получаем данные о раздачах за предыдущее обновление
            $topics_ids = array_keys($topics_result);
            $in = str_repeat('?,', count($topics_ids) - 1) . '?';
            $topics_data_previous = Db::query_database(
                "SELECT id,se,rg,qt,ds,ps,length(na) as lgth FROM Topics WHERE id IN ($in)",
                $topics_ids,
                true,
                PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE
            );
            unset($topics_ids);

            $topicsKeepersFromForum = [];
            $dbTopicsKeepers = [];
            $db_topics_renew = $db_topics_update = [];
            // разбираем раздачи
            // topic_id => array( tor_status, seeders, reg_time, tor_size_bytes, keeping_priority, keepers, seeder_last_seen, info_hash )
            foreach ($topics_result as $topic_id => $topic_raw) {
                if (empty($topic_raw)) {
                    continue;
                }

                if (count($topic_raw) < 6) {
                    throw new Exception("Error: Недостаточно элементов в ответе");
                }
                $topic_data = array_combine($topic_keys, $topic_raw);
                unset($topic_raw);

                if (
                    !in_array($topic_data['tor_status'], $allowedTorrentStatuses)
                    || $topic_data['keeping_priority'] == 2
                ) {
                    continue;
                }

                $days_update = 0;
                $sum_updates = 1;
                $sum_seeders = $topic_data['seeders'];

                // запоминаем имеющиеся данные о раздаче в локальной базе
                if (isset($topics_data_previous[$topic_id])) {
                    $previous_data = $topics_data_previous[$topic_id];
                }

                // удалить перерегистрированную раздачу и раздачу с устаревшими сидами
                // в том числе, чтобы очистить значения сидов для старой раздачи
                if (
                    isset($previous_data['rg'])
                    && $previous_data['rg'] != $topic_data['reg_time']
                ) {
                    $topics_delete[] = $topic_id;
                    $isTopicDataDelete = true;
                } else {
                    $isTopicDataDelete = false;
                }

                // Если нет доп. данных о раздаче, их надо получить. topic_title, poster_id
                if (
                    empty($previous_data)
                    || $isTopicDataDelete
                    || $previous_data['lgth'] === 0 // Пустое название
                    || $previous_data['ps'] === 0   // Нет автора раздачи
                ) {
                    $db_topics_renew[$topic_id] = [
                        'id' => $topic_id,
                        'ss' => $forum_id,
                        'na' => '',
                        'hs' => $topic_data['info_hash'],
                        'se' => $sum_seeders,
                        'si' => $topic_data['tor_size_bytes'],
                        'st' => $topic_data['tor_status'],
                        'rg' => $topic_data['reg_time'],
                        'qt' => $sum_updates,
                        'ds' => $days_update,
                        'pt' => $topic_data['keeping_priority'],
                        'ps' => 0,
                        'ls' => $topic_data['seeder_last_seen'],
                    ];
                    if (!empty($topic_data['keepers'])) {
                        $topicsKeepersFromForum[$topic_id] = $topic_data['keepers'];
                    }
                    unset($previous_data);
                    continue;
                }

                // алгоритм нахождения среднего значения сидов
                if ($cfg['avg_seeders']) {
                    $days_update = $previous_data['ds'];
                    // по прошествии дня
                    if ($daysDiffAdjusted > 0) {
                        $days_update++;
                    } else {
                        $sum_updates += $previous_data['qt'];
                        $sum_seeders += $previous_data['se'];
                    }
                }
                unset($previous_data);

                $db_topics_update[$topic_id] = [
                    'id' => $topic_id,
                    'ss' => $forum_id,
                    'se' => $sum_seeders,
                    'st' => $topic_data['tor_status'],
                    'qt' => $sum_updates,
                    'ds' => $days_update,
                    'pt' => $topic_data['keeping_priority'],
                    'ls' => $topic_data['seeder_last_seen'],
                ];
                if (!empty($topic_data['keepers'])) {
                    $topicsKeepersFromForum[$topic_id] = $topic_data['keepers'];
                }

                unset($topic_id, $topic_data);
            }
            unset($topics_result);

            if (!empty($topicsKeepersFromForum)) {
                foreach ($topicsKeepersFromForum as $keeperTopicID => $keepersIDs) {
                    foreach ($keepersIDs as $keeperID) {
                        if (isset($keepersUserData[$keeperID])) {
                            $dbTopicsKeepers[] = [
                                'topic_id'    => $keeperTopicID,
                                'keeper_id'   => $keeperID,
                                'keeper_name' => $keepersUserData[$keeperID][0],
                            ];
                        }
                    }
                }

                // обновление данных в базе о сидах-хранителях
                $dbTopicsKeepersChunks = array_chunk($dbTopicsKeepers, 250);
                foreach ($dbTopicsKeepersChunks as $dbTopicsKeepersChunk) {
                    $select = Db::combine_set($dbTopicsKeepersChunk, 'topic_id');
                    Db::query_database("INSERT INTO temp.KeepersSeedersNew (topic_id, keeper_id, keeper_name) $select");
                    unset($dbTopicsKeepersChunk, $select);
                }
                unset($topicsKeepersFromForum, $dbTopicsKeepersChunks);
            }

            unset($topics_data_previous);

            // вставка данных в базу о новых раздачах
            if (count($db_topics_renew)) {
                $topics_renew_ids = array_keys($db_topics_renew);
                $in = str_repeat('?,', count($topics_renew_ids) - 1) . '?';
                $topics_data = $api->getTorrentTopicData($topics_renew_ids);
                unset($topics_renew_ids);
                if (empty($topics_data)) {
                    throw new Exception("Error: Не получены дополнительные данные о раздачах");
                }
                foreach ($topics_data as $topic_id => $topic_data) {
                    if (empty($topic_data)) {
                        continue;
                    }
                    if (isset($db_topics_renew[$topic_id])) {
                        $db_topics_renew[$topic_id]['na'] = $topic_data['topic_title'];
                        $db_topics_renew[$topic_id]['ps'] = $topic_data['poster_id'];
                    }
                }
                unset($topics_data);

                $select = Db::combine_set($db_topics_renew);
                Db::query_database("INSERT INTO temp.TopicsRenew $select");

                unset($db_topics_renew, $select);
            }
            unset($db_topics_renew);

            // обновление данных в базе о существующих раздачах
            if (count($db_topics_update)) {
                $select = Db::combine_set($db_topics_update);
                Db::query_database("INSERT INTO temp.TopicsUpdate $select");

                unset($db_topics_update, $select);
            }
        }

        Log::append(sprintf(
            'Обновление списка раздач подраздела № %d завершено за %s, %d шт',
            $forum_id,
            Timers::getExecTime("update_forum_$forum_id"),
            $topics_count
        ));
    }
}

// удаляем перерегистрированные раздачи
// чтобы очистить значения сидов для старой раздачи
if (isset($topics_delete)) {
    $topics_delete_chunk = array_chunk($topics_delete, 500);
    foreach ($topics_delete_chunk as $topics_delete) {
        $in = str_repeat('?,', count($topics_delete) - 1) . '?';
        Db::query_database(
            "DELETE FROM Topics WHERE id IN ($in)",
            $topics_delete
        );
    }
    unset($topics_delete, $topics_delete_chunk);
}

$countKeepersSeeders = Db::query_count('SELECT COUNT() FROM temp.KeepersSeedersNew');
$countTopicsUpdate   = Db::query_count('SELECT COUNT() FROM temp.TopicsUpdate');
$countTopicsRenew    = Db::query_count('SELECT COUNT() FROM temp.TopicsRenew');

if ($countKeepersSeeders > 0) {
    Log::append("Запись в базу данных списка сидов-хранителей...");
    Db::query_database("INSERT INTO KeepersSeeders SELECT * FROM temp.KeepersSeedersNew");
    Db::query_database(
        "DELETE FROM KeepersSeeders WHERE topic_id || keeper_id NOT IN (
            SELECT ks.topic_id || ks.keeper_id
            FROM temp.KeepersSeedersNew tmp
            LEFT JOIN KeepersSeeders ks ON tmp.topic_id = ks.topic_id AND tmp.keeper_id = ks.keeper_id
            WHERE ks.topic_id IS NOT NULL
        )"
    );
}

if ($countTopicsUpdate > 0 || $countTopicsRenew > 0) {
    // переносим данные в основную таблицу
    Db::query_database(
        "INSERT INTO Topics (id,ss,se,st,qt,ds,pt,ls)
        SELECT * FROM temp.TopicsUpdate"
    );
    Db::query_database(
        "INSERT INTO Topics (id,ss,na,hs,se,si,st,rg,qt,ds,pt,ps,ls)
        SELECT * FROM temp.TopicsRenew"
    );
    $forums_ids = array_keys($forumsUpdateTime);
    $in = implode(',', $forums_ids);
    Db::query_database(
        "DELETE FROM Topics WHERE id IN (
            SELECT Topics.id FROM Topics
            LEFT JOIN temp.TopicsUpdate ON Topics.id = temp.TopicsUpdate.id
            LEFT JOIN temp.TopicsRenew ON Topics.id = temp.TopicsRenew.id
            WHERE temp.TopicsUpdate.id IS NULL AND temp.TopicsRenew.id IS NULL AND Topics.ss IN ($in) AND Topics.pt <> 2
        )"
    );
    // время последнего обновления для каждого подраздела
    $forums_update_chunk = array_chunk($forumsUpdateTime, 500, true);
    foreach ($forums_update_chunk as $forums_update_part) {
        $select = Db::combine_set($forums_update_part);
        Db::query_database("INSERT INTO temp.UpdateTimeNow $select");
        unset($select);
    }
    Db::query_database(
        "INSERT INTO UpdateTime (id,ud)
        SELECT id,ud FROM temp.UpdateTimeNow"
    );
    // Записываем время обновления.
    set_last_update_time(7777);

    Log::append(sprintf(
        'Обработано хранимых подразделов: %d шт, раздач в них %d',
        count($forumsUpdateTime),
        $countTopicsUpdate + $countTopicsRenew
    ));
}
Log::append("Обновление сведений о раздачах завершено за " . Timers::getExecTime('topics_update'));

// дёргаем скрипт
include_once dirname(__FILE__) . '/tor_clients.php';


Log::append("Обновление всех данных завершено за " . Timers::getExecTime('full_update'));
