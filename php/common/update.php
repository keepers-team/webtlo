<?php

$starttime = microtime(true);

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/api.php';

Log::append("Начато обновление сведений о раздачах...");

// обновляем дерево подразделов
include_once dirname(__FILE__) . '/forum_tree.php';

// получение настроек
if (!isset($cfg)) {
    $cfg = get_settings();
}

// создаём временные таблицы
Db::query_database(
    "CREATE TEMP TABLE UpdateTimeNow AS
    SELECT id,ud FROM UpdateTime WHERE 0 = 1"
);
Db::query_database(
    "CREATE TEMP TABLE TopicsUpdate AS
    SELECT id,ss,se,st,qt,ds FROM Topics WHERE 0 = 1"
);
Db::query_database(
    "CREATE TEMP TABLE TopicsRenew AS
    SELECT id,ss,na,hs,se,si,st,rg,qt,ds FROM Topics WHERE 0 = 1"
);

// подключаемся к api
if (!isset($api)) {
    $api = new Api($cfg['api_url'], $cfg['api_key']);
}

// все открытые раздачи
$tor_status = array(0, 2, 3, 8, 10);

// время текущего и предыдущего обновления
$current_update_time = new DateTime();
$previous_update_time = new DateTime();

if (isset($cfg['subsections'])) {

    foreach ($cfg['subsections'] as $forum_id => $subsection) {

        // получаем дату предыдущего обновления
        $update_time = Db::query_database(
            "SELECT ud FROM UpdateTime WHERE id = ?",
            array($forum_id),
            true,
            PDO::FETCH_COLUMN
        );

        // при первом обновлении
        if (empty($update_time[0])) {
            $update_time[0] = 0;
        }

        $time_diff = time() - $update_time[0];

        // если не прошёл час
        if ($time_diff < 3600) {
            Log::append("Warning: Не требуется обновление для подраздела № " . $forum_id);
            continue;
        }

        // получаем данные о раздачах
        $topics_data = $api->get_forum_topics_data($forum_id);

        if (empty($topics_data['result'])) {
            throw new Exception("Error: Не получены данные о подразделе № " . $forum_id);
        }

        // количество и вес раздач
        $topics_count = count($topics_data['result']);
        $topics_size = $topics_data['total_size_bytes'];

        // Log::append( "Список раздач подраздела № $forum_id получен ($topics_count шт.)" );

        // запоминаем время обновления каждого подраздела
        $forums_update_time[$forum_id]['ud'] = $topics_data['update_time'];

        // текущее обновление в DateTime
        $current_update_time->setTimestamp($topics_data['update_time']);

        // предыдущее обновление в DateTime
        $previous_update_time->setTimestamp($update_time[0])->setTime(0, 0, 0);

        // разница в днях между обновлениями сведений
        $days_diff = $current_update_time->diff($previous_update_time)->format('%d');

        // разбиваем result по 500 раздач
        $topics_result = array_chunk($topics_data['result'], 500, true);
        unset($topics_data);

        foreach ($topics_result as $topics_result) {

            // получаем данные о раздачах за предыдущее обновление
            $topics_ids = array_keys($topics_result);
            $in = str_repeat('?,', count($topics_ids) - 1) . '?';
            $topics_data_previous = Db::query_database(
                "SELECT id,se,rg,qt,ds,length(na) as lgth FROM Topics WHERE id IN ($in)",
                $topics_ids,
                true,
                PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE
            );
            unset($topics_ids);

            // разбираем раздачи
            // topic_id => array( tor_status, seeders, reg_time, tor_size_bytes )
            foreach ($topics_result as $topic_id => $topic_data) {

                if (empty($topic_data)) {
                    continue;
                }

                if (count($topic_data) < 4) {
                    throw new Exception("Error: Недостаточно элементов в ответе");
                }

                if (!in_array($topic_data[0], $tor_status)) {
                    continue;
                }

                $days_update = 0;
                $sum_updates = 1;
                $sum_seeders = $topic_data[1];

                // запоминаем имеющиеся данные о раздаче в локальной базе
                if (isset($topics_data_previous[$topic_id])) {
                    $previous_data = $topics_data_previous[$topic_id];
                }

                // получить для раздачи info_hash и topic_title
                // если новая раздача или перерегистрированная
                if (
                    empty($previous_data)
                    || $previous_data['rg'] != $topic_data[2]
                    || $previous_data['lgth'] == 0
                ) {
                    $db_topics_renew[$topic_id] = array(
                        'id' => $topic_id,
                        'ss' => $forum_id,
                        'na' => '',
                        'hs' => '',
                        'se' => $sum_seeders,
                        'si' => $topic_data[3],
                        'st' => $topic_data[0],
                        'rg' => $topic_data[2],
                        'qt' => $sum_updates,
                        'ds' => $days_update,
                    );
                    // удаляем перерегистрированую раздачу
                    // в том числе, чтобы очистить значения сидов для старой раздачи
                    if (isset($previous_data['id'])) {
                        $topics_delete[] = $topic_id;
                    }
                    unset($previous_data);
                    continue;
                }

                // алгоритм нахождения среднего значения сидов
                if ($cfg['avg_seeders']) {
                    $days_update = $previous_data['ds'];
                    // по прошествии дня
                    if ($days_diff > 0) {
                        $days_update++;
                    } else {
                        $sum_updates += $previous_data['qt'];
                        $sum_seeders += $previous_data['se'];
                    }
                }
                unset($previous_data);

                $db_topics_update[$topic_id] = array(
                    'id' => $topic_id,
                    'ss' => $forum_id,
                    'se' => $sum_seeders,
                    'st' => $topic_data[0],
                    'qt' => $sum_updates,
                    'ds' => $days_update,
                );
            }
            unset($topics_data_previous);

            // вставка данных в базу о новых раздачах
            if (isset($db_topics_renew)) {
                $topics_renew_ids = array_keys($db_topics_renew);
                $in = str_repeat('?,', count($topics_renew_ids) - 1) . '?';
                $topics_data = $api->get_tor_topic_data($topics_renew_ids);
                unset($topics_renew_ids);
                if (empty($topics_data)) {
                    throw new Exception("Error: Не получены дополнительные данные о раздачах");
                }
                foreach ($topics_data as $topic_id => $topic_data) {
                    if (empty($topic_data)) {
                        continue;
                    }
                    if (isset($db_topics_renew[$topic_id])) {
                        $db_topics_renew[$topic_id]['hs'] = $topic_data['info_hash'];
                        $db_topics_renew[$topic_id]['na'] = $topic_data['topic_title'];
                    }
                }
                unset($topics_data);
                $select = Db::combine_set($db_topics_renew);
                unset($db_topics_renew);
                Db::query_database("INSERT INTO temp.TopicsRenew $select");
                unset($select);
            }
            unset($db_topics_renew);

            // обновление данных в базе о существующих раздачах
            if (isset($db_topics_update)) {
                $select = Db::combine_set($db_topics_update);
                unset($db_topics_update);
                Db::query_database("INSERT INTO temp.TopicsUpdate $select");
                unset($select);
            }
            unset($db_topics_update);

        }
        unset($topics_result);
    }

}

// удаляем перерегистрированные раздачи
// чтобы очистить значения сидов для старой раздачи
if (isset($topics_delete)) {
    $topics_delete = array_chunk($topics_delete, 500);
    foreach ($topics_delete as $topics_delete) {
        $in = str_repaet('?,', count($topics_delete) - 1) . '?';
        Db::query_database(
            "DELETE FROM Topics WHERE id IN ($in)",
            $topics_delete
        );
    }
}

$count_update = Db::query_database(
    "SELECT COUNT() FROM temp.TopicsUpdate",
    array(),
    true,
    PDO::FETCH_COLUMN
);
$count_renew = Db::query_database(
    "SELECT COUNT() FROM temp.TopicsRenew",
    array(),
    true,
    PDO::FETCH_COLUMN
);

if (
    $count_update[0] > 0
    || $count_renew[0] > 0
) {
    Log::append("Обработано подразделов: " . count($forums_update_time) . " шт.");
    Log::append("Запись в базу данных сведений о раздачах...");
    // переносим данные в основную таблицу
    Db::query_database(
        "INSERT INTO Topics (id,ss,se,st,qt,ds)
        SELECT * FROM temp.TopicsUpdate"
    );
    Db::query_database(
        "INSERT INTO Topics (id,ss,na,hs,se,si,st,rg,qt,ds)
        SELECT * FROM temp.TopicsRenew"
    );
    $forums_ids = array_keys($forums_update_time);
    $in = implode(',', $forums_ids);
    Db::query_database(
        "DELETE FROM Topics WHERE id IN (
            SELECT Topics.id FROM Topics
            LEFT JOIN temp.TopicsUpdate ON Topics.id = temp.TopicsUpdate.id
            LEFT JOIN temp.TopicsRenew ON Topics.id = temp.TopicsRenew.id
            WHERE temp.TopicsUpdate.id IS NULL AND temp.TopicsRenew.id IS NULL AND Topics.ss IN ($in)
        )"
    );
    // время последнего обновления для каждого подраздела
    $forums_update_time = array_chunk($forums_update_time, 500, true);
    foreach ($forums_update_time as $forums_update_time) {
        $select = Db::combine_set($forums_update_time);
        Db::query_database("INSERT INTO temp.UpdateTimeNow $select");
        unset($select);
    }
    Db::query_database(
        "INSERT INTO UpdateTime (id,ud)
        SELECT id,ud FROM temp.UpdateTimeNow"
    );
    // время окончания обновления
    Db::query_database(
        "INSERT INTO UpdateTime (id,ud) SELECT 7777,?",
        array(time())
    );
}

// дёргаем скрипт
include_once dirname(__FILE__) . '/tor_clients.php';

$endtime = microtime(true);

Log::append("Обновление сведений о раздачах завершено за " . convert_seconds($endtime - $starttime));
