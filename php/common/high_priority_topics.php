<?php

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/api.php';

// получение настроек
if (!isset($cfg)) {
    $cfg = get_settings();
}
// создаём временные таблицы
Db::query_database(
    "CREATE TEMP TABLE HighTopicsUpdate AS
    SELECT id,ss,se,st,qt,ds,pt FROM Topics WHERE 0 = 1"
);
Db::query_database(
    "CREATE TEMP TABLE HighTopicsRenew AS
    SELECT id,ss,na,hs,se,si,st,rg,qt,ds,pt FROM Topics WHERE 0 = 1"
);
// подключаемся к api
if (!isset($api)) {
    $api = new Api($cfg['api_address'], $cfg['api_key']);
    // применяем таймауты
    $api->setUserConnectionOptions($cfg['curl_setopt']['api']);
}
// все открытые раздачи
$torrentStatus = [0, 2, 3, 8, 10];
// время текущего и предыдущего обновления
$currentUpdateTime = new DateTime();
$previousUpdateTime = new DateTime();
// получаем дату предыдущего обновления
$updateTime = Db::query_database(
    "SELECT ud FROM UpdateTime WHERE id = ?",
    [9999],
    true,
    PDO::FETCH_COLUMN
);
// при первом обновлении
if (empty($updateTime[0])) {
    $updateTime[0] = 0;
}
// разница между обновлениями
$timeDiff = time() - $updateTime[0];
// если не прошёл час
if ($timeDiff < 3600) {
    Log::append("Notice: Не требуется обновление списка высокоприоритетных раздач");
} else {
    // получаем данные о раздачах
    $topicsHighPriorityData = $api->getTopicsHighPriority();
    if (empty($topicsHighPriorityData['result'])) {
        Log::append("Error: Не получены данные о высокоприоритетных раздачах");
    } else {
        // время последнего обновления данных на api
        $topicsHighPriorityUpdateTime = $topicsHighPriorityData['update_time'];
        // количество раздач
        $topicsHighPriorityTotalCount = 0;
        // текущее обновление в DateTime
        $currentUpdateTime->setTimestamp($topicsHighPriorityData['update_time']);
        // предыдущее обновление в DateTime
        $previousUpdateTime->setTimestamp($updateTime[0])->setTime(0, 0, 0);
        // разница в днях между обновлениями сведений
        $daysDiffAdjusted = $currentUpdateTime->diff($previousUpdateTime)->format('%d');
        // разбиваем result по 500 раздач
        $topicsHighPriorityResult = array_chunk($topicsHighPriorityData['result'], 500, true);
        unset($topicsHighPriorityData);
        // проходим по всем раздачам
        foreach ($topicsHighPriorityResult as $topicsHighPriorityResult) {
            // получаем данные о раздачах за предыдущее обновление
            $topicsIDs = array_keys($topicsHighPriorityResult);
            $in = str_repeat('?,', count($topicsIDs) - 1) . '?';
            $previousTopicsData = Db::query_database(
                "SELECT id,se,rg,qt,ds,length(na) as lgth FROM Topics WHERE id IN ($in)",
                $topicsIDs,
                true,
                PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE
            );
            unset($topicsIDs);
            // разбираем раздачи
            // topic_id => array( tor_status, seeders, reg_time, tor_size_bytes, forum_id )
            foreach ($topicsHighPriorityResult as $topicID => $topicData) {
                if (empty($topicData)) {
                    continue;
                }
                if (count($topicData) < 5) {
                    throw new Exception("Error: Недостаточно элементов в ответе");
                }
                if (!in_array($topicData[0], $torrentStatus)) {
                    continue;
                }
                $numberDaysUpdate = 0;
                $sumUpdates = 1;
                $sumSeeders = $topicData[1];
                // запоминаем имеющиеся данные о раздаче в локальной базе
                if (isset($previousTopicsData[$topicID])) {
                    $previousTopicData = $previousTopicsData[$topicID];
                }
                // удалить перерегистрированную раздачу
                // в том числе, чтобы очистить значения сидов для старой раздачи
                if (
                    isset($previousTopicData['rg'])
                    && $previousTopicData['rg'] != $topicData[2]
                ) {
                    $topicsDelete[] = $topicID;
                    $isTopicDataDelete = true;
                } else {
                    $isTopicDataDelete = false;
                }
                // получить для раздачи info_hash и topic_title
                if (
                    empty($previousTopicData)
                    || $previousTopicData['lgth'] == 0
                    || $isTopicDataDelete
                ) {
                    $insertTopicsRenew[$topicID] = [
                        'id' => $topicID,
                        'ss' => $topicData[4],
                        'na' => '',
                        'hs' => '',
                        'se' => $sumSeeders,
                        'si' => $topicData[3],
                        'st' => $topicData[0],
                        'rg' => $topicData[2],
                        'qt' => $sumUpdates,
                        'ds' => $numberDaysUpdate,
                        'pt' => 2,
                    ];
                    unset($previousTopicData);
                    continue;
                }
                // алгоритм нахождения среднего значения сидов
                if ($cfg['avg_seeders']) {
                    $numberDaysUpdate = $previousTopicData['ds'];
                    // по прошествии дня
                    if ($daysDiffAdjusted > 0) {
                        $numberDaysUpdate++;
                    } else {
                        $sumUpdates += $previousTopicData['qt'];
                        $sumSeeders += $previousTopicData['se'];
                    }
                }
                unset($previousTopicData);
                $insertTopicsUpdate[$topicID] = [
                    'id' => $topicID,
                    'ss' => $topicData[4],
                    'se' => $sumSeeders,
                    'st' => $topicData[0],
                    'qt' => $sumUpdates,
                    'ds' => $numberDaysUpdate,
                    'pt' => 2,
                ];
            }
            unset($previousTopicsData);
            // вставка данных в базу о новых раздачах
            if (isset($insertTopicsRenew)) {
                $topicsIDsRenew = array_keys($insertTopicsRenew);
                $in = str_repeat('?,', count($topicsIDsRenew) - 1) . '?';
                $topicsHighPriorityData = $api->getTorrentTopicData($topicsIDsRenew);
                unset($topicsIDsRenew);
                if (empty($topicsHighPriorityData)) {
                    throw new Exception("Error: Не получены дополнительные данные о раздачах");
                }
                foreach ($topicsHighPriorityData as $topicID => $topicData) {
                    if (empty($topicData)) {
                        continue;
                    }
                    if (isset($insertTopicsRenew[$topicID])) {
                        $insertTopicsRenew[$topicID]['hs'] = $topicData['info_hash'];
                        $insertTopicsRenew[$topicID]['na'] = $topicData['topic_title'];
                    }
                }
                unset($topicsHighPriorityData);
                $select = Db::combine_set($insertTopicsRenew);
                unset($insertTopicsRenew);
                Db::query_database("INSERT INTO temp.HighTopicsRenew $select");
                unset($select);
            }
            unset($insertTopicsRenew);
            // обновление данных в базе о существующих раздачах
            if (isset($insertTopicsUpdate)) {
                $select = Db::combine_set($insertTopicsUpdate);
                unset($insertTopicsUpdate);
                Db::query_database("INSERT INTO temp.HighTopicsUpdate $select");
                unset($select);
            }
            unset($insertTopicsUpdate);
        }
        unset($topicsHighPriorityResult);
        // удаляем перерегистрированные раздачи
        // чтобы очистить значения сидов для старой раздачи
        if (isset($topicsDelete)) {
            $topicsDelete = array_chunk($topicsDelete, 500);
            foreach ($topicsDelete as $topicsDelete) {
                $in = str_repeat('?,', count($topicsDelete) - 1) . '?';
                Db::query_database(
                    "DELETE FROM Topics WHERE id IN ($in)",
                    $topicsDelete
                );
            }
        }
        $countTopicsUpdate = Db::query_database(
            "SELECT COUNT() FROM temp.HighTopicsUpdate",
            [],
            true,
            PDO::FETCH_COLUMN
        );
        $countTopicsRenew = Db::query_database(
            "SELECT COUNT() FROM temp.HighTopicsRenew",
            [],
            true,
            PDO::FETCH_COLUMN
        );
        if (
            $countTopicsUpdate[0] > 0
            || $countTopicsRenew[0] > 0
        ) {
            // переносим данные в основную таблицу
            Db::query_database(
                "INSERT INTO Topics (id,ss,se,st,qt,ds,pt)
                    SELECT * FROM temp.HighTopicsUpdate"
            );
            Db::query_database(
                "INSERT INTO Topics (id,ss,na,hs,se,si,st,rg,qt,ds,pt)
                    SELECT * FROM temp.HighTopicsRenew"
            );
            Db::query_database(
                "DELETE FROM Topics WHERE id IN (
                    SELECT Topics.id FROM Topics
                    LEFT JOIN temp.HighTopicsUpdate ON Topics.id = temp.HighTopicsUpdate.id
                    LEFT JOIN temp.HighTopicsRenew ON Topics.id = temp.HighTopicsRenew.id
                    WHERE temp.HighTopicsUpdate.id IS NULL AND temp.HighTopicsRenew.id IS NULL AND Topics.pt = 2
                )"
            );
            // время окончания обновления
            Db::query_database(
                "INSERT INTO UpdateTime (id,ud) SELECT 9999,?",
                [$topicsHighPriorityUpdateTime]
            );
            $countTopicsTotalUpdate = $countTopicsUpdate[0] + $countTopicsRenew[0];
            Log::append("Обработано высокоприоритетных раздач: " . $countTopicsTotalUpdate . " шт.");
        }
    }
}
