<?php

include_once dirname(__FILE__) . '/../../vendor/autoload.php';
include_once dirname(__FILE__) . '/../classes/api.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Legacy\Db;
use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\Module\CloneTable;
use KeepersTeam\Webtlo\Module\LastUpdate;
use KeepersTeam\Webtlo\Module\Topics;
use KeepersTeam\Webtlo\Timers;

App::init();

// получение настроек
if (!isset($cfg)) {
    $cfg = App::getSettings();
}

// Хранимые подразделы.
$subsections = array_keys($cfg['subsections'] ?? []);

if ($cfg['update']['priority'] == 0) {
    Log::append('Notice: Обновление списка раздач с высоким приоритетом отключено в настройках.');
    LastUpdate::setTime(UpdateMark::HIGH_PRIORITY->value, 0);

    // Если обновление списка высокоприоритетных раздач отключено, то удалим лишние записи в БД.
    if (count($subsections)) {
        $sub = KeysObject::create($subsections);
        Db::query_database(
            "DELETE FROM Topics WHERE keeping_priority = 2 AND Topics.forum_id NOT IN ($sub->keys)",
            $sub->values
        );
    }
    return;
}

Timers::start('hp_topics');

Log::append('Info: Начато обновление списка высокоприоритетных раздач...');
// получаем дату предыдущего обновления
$updateTime = LastUpdate::getTime(UpdateMark::HIGH_PRIORITY->value);
// если не прошло два часа
if (time() - $updateTime < 7200) {
    Log::append("Notice: Не требуется обновление списка высокоприоритетных раздач");
    return;
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

// Обновляемые раздачи.
$tabHighUpdate = CloneTable::create(
    'Topics',
    [
        'id',
        'forum_id',
        'seeders',
        'status',
        'seeders_updates_today',
        'seeders_updates_days',
        'keeping_priority',
        'poster',
    ],
    'id',
    'HighUpdate'
);

// Новые раздачи.
$tabHighRenew = CloneTable::create(
    'Topics',
    [
        'id',
        'forum_id',
        'name',
        'info_hash',
        'seeders',
        'size',
        'status',
        'reg_time',
        'seeders_updates_today',
        'seeders_updates_days',
        'keeping_priority',
        'poster',
        'seeder_last_seen',
    ],
    'id',
    'HighRenew'
);


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

$topicsKeys = $topicsHighPriorityData['format']['topic_id'];
$flipKeys   = array_flip($topicsKeys);

// Убираем раздачи, из разделов, которые храним.
$topicsHighPriority = array_filter($topicsHighPriorityData['result'], function ($el) use ($flipKeys, $subsections) {
    return !in_array($el[$flipKeys['forum_id']], $subsections);
});

// Разбиваем список раздач по 500 шт.
$topicsHighPriority = array_chunk($topicsHighPriority, 500, true);

unset($topicsHighPriorityData);

// Приоритетт хранения раздач.
$keepingPriority = 2;

// проходим по всем раздачам
foreach ($topicsHighPriority as $topicsHighPriorityResult) {
    // получаем данные о раздачах за предыдущее обновление
    $selectTopics = KeysObject::create(array_keys($topicsHighPriorityResult));
    $previousTopicsData = Db::query_database(
        "
            SELECT id, seeders, reg_time, seeders_updates_today, seeders_updates_days, poster, length(name) as lgth
            FROM Topics
            WHERE id IN ($selectTopics->keys)
        ",
        $selectTopics->values,
        true,
        PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE
    );
    unset($selectTopics);

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
        if (!in_array($topicData['tor_status'], Topics::VALID_STATUSES)) {
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
            isset($previousTopicData['reg_time'])
            && $previousTopicData['reg_time'] != $topicData['reg_time']
        ) {
            $topicsDelete[]    = $topicID;
            $isTopicDataDelete = true;
        } else {
            $isTopicDataDelete = false;
        }
        // получить для раздачи info_hash, topic_title, poster_id, seeder_last_seen
        if (
            empty($previousTopicData)
            || $isTopicDataDelete
            || $previousTopicData['lgth'] == 0 // Пустое название
            || $previousTopicData['poster'] === 0  // Нет автора раздачи
        ) {
            $insertTopicsRenew[$topicID] = array_combine(
                $tabHighRenew->keys,
                [
                    $topicID,
                    $topicData['forum_id'],
                    '',
                    '',
                    $sumSeeders,
                    $topicData['tor_size_bytes'],
                    $topicData['tor_status'],
                    $topicData['reg_time'],
                    $sumUpdates,
                    $daysUpdate,
                    $keepingPriority,
                    0,
                    0,
                ]
            );
            unset($previousTopicData);
            continue;
        }

        // алгоритм нахождения среднего значения сидов
        if ($cfg['avg_seeders']) {
            $daysUpdate = $previousTopicData['seeders_updates_days'];
            // по прошествии дня
            if ($daysDiffAdjusted > 0) {
                $daysUpdate++;
            } else {
                $sumUpdates += $previousTopicData['seeders_updates_today'];
                $sumSeeders += $previousTopicData['seeders'];
            }
        }

        $insertTopicsUpdate[$topicID] = array_combine(
            $tabHighUpdate->keys,
            [
                $topicID,
                $topicData['forum_id'],
                $sumSeeders,
                $topicData['tor_status'],
                $sumUpdates,
                $daysUpdate,
                $keepingPriority,
                $previousTopicData['poster'],
            ]
        );
        unset($previousTopicData);
    }
    unset($previousTopicsData, $topicsHighPriorityResult);

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
                $insertTopicsRenew[$topicID]['info_hash']        = $topicData['info_hash'];
                $insertTopicsRenew[$topicID]['name']             = $topicData['topic_title'];
                $insertTopicsRenew[$topicID]['poster']           = $topicData['poster_id'];
                $insertTopicsRenew[$topicID]['seeder_last_seen'] = $topicData['seeder_last_seen'];
            }
        }
        unset($topicsHighPriorityData);

        $tabHighRenew->cloneFill($insertTopicsRenew);
        unset($insertTopicsRenew);
    }

    // обновление данных в базе о существующих раздачах
    if (isset($insertTopicsUpdate)) {
        $tabHighUpdate->cloneFill($insertTopicsUpdate);
        unset($insertTopicsUpdate);
    }
    unset($insertTopicsUpdate);
}
unset($topicsHighPriority);

// удаляем перерегистрированные раздачи
// чтобы очистить значения сидов для старой раздачи
if (isset($topicsDelete)) {
    Topics::deleteTopicsByIds($topicsDelete);
    unset($topicsDelete);
}

$countTopicsUpdate = $tabHighUpdate->cloneCount();
$countTopicsRenew  = $tabHighRenew->cloneCount();
if ($countTopicsUpdate > 0 || $countTopicsRenew > 0) {
    // переносим данные в основную таблицу
    $tabHighUpdate->moveToOrigin();
    $tabHighRenew->moveToOrigin();

    // Удалим раздачи с высоким приоритетом, которых нет во временных таблицах за исключением хранимых подразделов.
    $exclude = KeysObject::create($subsections);
    Db::query_database(
        "
            DELETE FROM Topics
            WHERE id IN (
                SELECT Topics.id
                FROM Topics
                LEFT JOIN $tabHighUpdate->clone AS thu ON Topics.id = thu.id
                LEFT JOIN $tabHighRenew->clone  AS thr ON Topics.id = thr.id
                WHERE thu.id IS NULL AND thr.id IS NULL
                    AND Topics.keeping_priority = 2
                    AND Topics.forum_id NOT IN ($exclude->keys)
            )
        ",
        $exclude->values
    );
    // Записываем время обновления.
    LastUpdate::setTime(UpdateMark::HIGH_PRIORITY->value, $topicsHighPriorityUpdateTime);

    Log::append(sprintf(
        'Info: Обновление высокоприоритетных раздач завершено за %s, обработано раздач: %d шт',
        Timers::getExecTime('hp_topics'),
        $countTopicsUpdate + $countTopicsRenew
    ));
}
