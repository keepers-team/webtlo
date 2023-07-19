<?php

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/api.php';

Timers::start('hp_topics');
/** Ид подраздела обновления высокоприоритетных раздач */
const HIGH_PRIORITY_UPDATE = 9999;

// получаем дату предыдущего обновления
$updateTime = get_last_update_time(HIGH_PRIORITY_UPDATE);
// если не прошёл час
if (time() - $updateTime < 3600) {
    Log::append("Notice: Не требуется обновление списка высокоприоритетных раздач");
    return;
}

// получение настроек
if (!isset($cfg)) {
    $cfg = get_settings();
}

// подключаемся к api
if (!isset($api)) {
    $api = new Api($cfg['api_address'], $cfg['api_key']);
    // применяем таймауты
    $api->setUserConnectionOptions($cfg['curl_setopt']['api']);
}

// получаем данные о раздачах
$topicsHighPriorityData = $api->getTopicsHighPriority();
if (empty($topicsHighPriorityData['result'])) {
    Log::append("Error: Не получены данные о высокоприоритетных раздачах");
    return;
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

// все открытые раздачи
$allowedTorrentStatuses = [0, 2, 3, 8, 10];
// время текущего и предыдущего обновления
$currentUpdateTime  = new DateTime();
$previousUpdateTime = new DateTime();

// время последнего обновления данных на api
$topicsHighPriorityUpdateTime = $topicsHighPriorityData['update_time'];
// количество раздач
$topicsHighPriorityTotalCount = 0;
// текущее обновление в DateTime
$currentUpdateTime->setTimestamp($topicsHighPriorityData['update_time']);
// предыдущее обновление в DateTime
$previousUpdateTime->setTimestamp($updateTime)->setTime(0, 0);
// разница в днях между обновлениями сведений
$daysDiffAdjusted = $currentUpdateTime->diff($previousUpdateTime)->format('%d');
// разбиваем result по 500 раздач
$topicsHighPriorityResultChunk = array_chunk($topicsHighPriorityData['result'], 500, true);

$topicsKeys = $topicsHighPriorityData['format']['topic_id'];
unset($topicsHighPriorityData);

// проходим по всем раздачам
foreach ($topicsHighPriorityResultChunk as $topicsHighPriorityResult) {
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
    foreach ($topicsHighPriorityResult as $topicID => $topicRaw) {
        if (empty($topicRaw)) {
            continue;
        }
        if (count($topicRaw) < 5) {
            throw new Exception("Error: Недостаточно элементов в ответе");
        }
        $topicData = array_combine($topicsKeys, $topicRaw);
        if (!in_array($topicData['tor_status'], $allowedTorrentStatuses)) {
            continue;
        }

        $daysUpdate = 0;
        $sumUpdates = 1;
        $sumSeeders = $topicData['seeders'];
        // запоминаем имеющиеся данные о раздаче в локальной базе
        if (isset($previousTopicsData[$topicID])) {
            $previousTopicData = $previousTopicsData[$topicID];
        }
        // удалить перерегистрированную раздачу
        // в том числе, чтобы очистить значения сидов для старой раздачи
        if (
            isset($previousTopicData['rg'])
            && $previousTopicData['rg'] != $topicData['reg_time']
        ) {
            $topicsDelete[]    = $topicID;
            $isTopicDataDelete = true;
        } else {
            $isTopicDataDelete = false;
        }
        // получить для раздачи info_hash и topic_title
        if (
            empty($previousTopicData)
            || $isTopicDataDelete
            || $previousTopicData['lgth'] == 0
        ) {
            $insertTopicsRenew[$topicID] = [
                'id' => $topicID,
                'ss' => $topicData['forum_id'],
                'na' => '',
                'hs' => '',
                'se' => $sumSeeders,
                'si' => $topicData['tor_size_bytes'],
                'st' => $topicData['tor_status'],
                'rg' => $topicData['reg_time'],
                'qt' => $sumUpdates,
                'ds' => $daysUpdate,
                'pt' => 2,
            ];
            unset($previousTopicData);
            continue;
        }
        // алгоритм нахождения среднего значения сидов
        if ($cfg['avg_seeders']) {
            $daysUpdate = $previousTopicData['ds'];
            // по прошествии дня
            if ($daysDiffAdjusted > 0) {
                $daysUpdate++;
            } else {
                $sumUpdates += $previousTopicData['qt'];
                $sumSeeders += $previousTopicData['se'];
            }
        }
        unset($previousTopicData);

        $insertTopicsUpdate[$topicID] = [
            'id' => $topicID,
            'ss' => $topicData['forum_id'],
            'se' => $sumSeeders,
            'st' => $topicData['tor_status'],
            'qt' => $sumUpdates,
            'ds' => $daysUpdate,
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
        unset($insertTopicsRenew, $select);
    }

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
    $topicsDeleteChunk = array_chunk($topicsDelete, 500);
    foreach ($topicsDeleteChunk as $topicsDeletePart) {
        $in = str_repeat('?,', count($topicsDeletePart) - 1) . '?';
        Db::query_database(
            "DELETE FROM Topics WHERE id IN ($in)",
            $topicsDeletePart
        );
        unset($topicsDeletePart, $im);
    }
    unset($topicsDelete, $topicsDeleteChunk);
}

$countTopicsUpdate = Db::query_count('SELECT COUNT() FROM temp.HighTopicsUpdate');
$countTopicsRenew  = Db::query_count('SELECT COUNT() FROM temp.HighTopicsRenew');
if ($countTopicsUpdate > 0 || $countTopicsRenew > 0) {
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
    // Записываем время обновления.
    set_last_update_time(HIGH_PRIORITY_UPDATE, $topicsHighPriorityUpdateTime);

    Log::append(sprintf(
        'Обновление высокоприоритетных раздач завершено за %s, обработано раздач: %d шт',
        Timers::getExecTime('hp_topics'),
        $countTopicsUpdate + $countTopicsRenew
    ));
}
