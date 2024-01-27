<?php

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\Legacy\Db;
use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\Module\CloneTable;
use KeepersTeam\Webtlo\Module\LastUpdate;
use KeepersTeam\Webtlo\Module\Topics;
use KeepersTeam\Webtlo\Timers;

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/api.php';

// обновляем дерево подразделов
include_once dirname(__FILE__) . '/forum_tree.php';

// получаем список подразделов
$forums_ids = Db::query_database(
    "SELECT id FROM Forums WHERE quantity > 0 AND size > 0",
    [],
    true,
    PDO::FETCH_COLUMN
);

if (empty($forums_ids)) {
    throw new Exception("Error: Не получен список подразделов");
}

// получение настроек
if (!isset($cfg)) {
    $cfg = App::getSettings();
}

// подключаемся к api
if (!isset($api)) {
    $api = new Api($cfg['api_address'], $cfg['api_key']);
    // применяем таймауты
    $api->setUserConnectionOptions($cfg['curl_setopt']['api']);
}


Timers::start('topics_update');
Log::append("Начато обновление сведений о раздачах всех подразделов...");

// Параметры таблиц.
$tabTime = CloneTable::create('UpdateTime');
// Обновляемые раздачи.
$tabTopicsUpdate = CloneTable::create(
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
        'seeder_last_seen',
    ],
    'id',
    'Update'
);
// Новые раздачи.
$tabTopicsRenew = CloneTable::create(
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
    'Renew'
);

// время текущего и предыдущего обновления
$currentUpdateTime  = new DateTime();
$previousUpdateTime = new DateTime();

$forumsUpdateTime = [];
$noUpdateForums   = [];
$subsections = array_keys($cfg['subsections'] ?? []);

// обновим каждый хранимый подраздел
foreach ($forums_ids as $forum_id) {
    // Пропускаем хранимые подразделы/
    if (in_array($forum_id, $subsections)) {
        continue;
    }

    // получаем дату предыдущего обновления
    $update_time = LastUpdate::getTime($forum_id);

    // если не прошёл час
    if (time() - $update_time < 3600) {
        $noUpdateForums[] = $forum_id;
        continue;
    }

    Timers::start("update_forum_$forum_id");
    // получаем данные о раздачах
    $topics_data = $api->getForumTopicsData($forum_id);
    if (empty($topics_data['result'])) {
        Log::append("Error: Не получены данные о подразделе № $forum_id");
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
    $topics_data = array_chunk($topics_data['result'], 500, true);

    foreach ($topics_data as $topics_result) {
        // получаем данные о раздачах за предыдущее обновление
        $selectTopics = KeysObject::create(array_keys($topics_result));
        $topics_data_previous = Db::query_database(
            "
                SELECT id, seeders, reg_time, seeders_updates_today, seeders_updates_days
                FROM Topics
                WHERE id IN ($selectTopics->keys)
            ",
            $selectTopics->values,
            true,
            PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE
        );
        unset($selectTopics);

        $topicsKeepersFromForum = [];
        $dbTopicsKeepers = [];
        $db_topics_renew = $db_topics_update = [];
        // разбираем раздачи
        // topic_id => [tor_status, seeders, reg_time, tor_size_bytes, keeping_priority,
        //              keepers, seeder_last_seen, info_hash, topic_poster]
        foreach ($topics_result as $topic_id => $topic_raw) {
            if (empty($topic_raw)) {
                continue;
            }

            if (count($topic_raw) < 6) {
                throw new Exception("Error: Недостаточно элементов в ответе");
            }
            $topic_data = array_combine($topic_keys, $topic_raw);
            unset($topic_raw);

            // Пропускаем раздачи в невалидных статусах. Или с высоким приоритетом.
            if (!in_array($topic_data['tor_status'], Topics::VALID_STATUSES)) {
                continue;
            }

            $days_update = 0;
            $sum_updates = 1;
            $sum_seeders = $topic_data['seeders'];

            // запоминаем имеющиеся данные о раздаче в локальной базе
            $previous_data = $topics_data_previous[$topic_id] ?? [];

            // удалить перерегистрированную раздачу и раздачу с устаревшими сидами
            // в том числе, чтобы очистить значения сидов для старой раздачи
            if (
                isset($previous_data['reg_time'])
                && $previous_data['reg_time'] != $topic_data['reg_time']
            ) {
                $topics_delete[] = $topic_id;
                $isTopicDataDelete = true;
            } else {
                $isTopicDataDelete = false;
            }

            // Новая или обновлённая раздача
            if (
                empty($previous_data)
                || $isTopicDataDelete
            ) {
                $db_topics_renew[$topic_id] = array_combine(
                    $tabTopicsRenew->keys,
                    [
                        $topic_id,
                        $forum_id,
                        '',
                        $topic_data['info_hash'],
                        $sum_seeders,
                        $topic_data['tor_size_bytes'],
                        $topic_data['tor_status'],
                        $topic_data['reg_time'],
                        $sum_updates,
                        $days_update,
                        $topic_data['keeping_priority'],
                        $topic_data['topic_poster'],
                        $topic_data['seeder_last_seen'],
                    ]
                );
                unset($previous_data);
                continue;
            }

            // алгоритм нахождения среднего значения сидов
            if ($cfg['avg_seeders']) {
                $days_update = $previous_data['seeders_updates_days'];
                // по прошествии дня
                if ($daysDiffAdjusted > 0) {
                    $days_update++;
                } else {
                    $sum_updates += $previous_data['seeders_updates_today'];
                    $sum_seeders += $previous_data['seeders'];
                }
            }
            unset($previous_data);

            $db_topics_update[$topic_id] = array_combine(
                $tabTopicsUpdate->keys,
                [
                    $topic_id,
                    $forum_id,
                    $sum_seeders,
                    $topic_data['tor_status'],
                    $sum_updates,
                    $days_update,
                    $topic_data['keeping_priority'],
                    $topic_data['topic_poster'],
                    $topic_data['seeder_last_seen'],
                ]
            );

            unset($topic_id, $topic_data);
        }
        unset($topics_result, $topics_data_previous);

        // вставка данных в базу о новых раздачах
        if (count($db_topics_renew)) {
            $tabTopicsRenew->cloneFill($db_topics_renew);
        }
        unset($db_topics_renew);

        // обновление данных в базе о существующих раздачах
        if (count($db_topics_update)) {
            $tabTopicsUpdate->cloneFill($db_topics_update);
        }
        unset($db_topics_update);
    }
    unset($topics_data);

    Log::append(sprintf(
        'Обновление списка раздач подраздела № %d завершено за %s, %d шт',
        $forum_id,
        Timers::getExecTime("update_forum_$forum_id"),
        $topics_count
    ));
}

if (count($noUpdateForums)) {
    Log::append(sprintf(
        'Notice: Обновление списков раздач не требуется для подразделов №№ %s.',
        implode(', ', $noUpdateForums)
    ));
}

// удаляем перерегистрированные раздачи
// чтобы очистить значения сидов для старой раздачи
if (isset($topics_delete)) {
    Topics::deleteTopicsByIds($topics_delete);
    unset($topics_delete);
}


$countTopicsUpdate = $tabTopicsUpdate->cloneCount();
$countTopicsRenew  = $tabTopicsRenew->cloneCount();
if ($countTopicsUpdate > 0 || $countTopicsRenew > 0) {
    // переносим данные в основную таблицу
    $tabTopicsUpdate->moveToOrigin();
    $tabTopicsRenew->moveToOrigin();

    $forums_ids = array_keys($forumsUpdateTime);
    $in = implode(',', $forums_ids);
    Db::query_database("
        DELETE FROM Topics
        WHERE id IN (
            SELECT Topics.id
            FROM Topics
            LEFT JOIN $tabTopicsUpdate->clone tpu ON Topics.id = tpu.id
            LEFT JOIN $tabTopicsRenew->clone tpr ON Topics.id = tpr.id
            WHERE tpu.id IS NULL AND tpr.id IS NULL AND Topics.forum_id IN ($in)
        )
    ");
    // время последнего обновления для каждого подраздела
    $tabTime->cloneFillChunk($forumsUpdateTime);
    $tabTime->moveToOrigin();

    Log::append(sprintf(
        'Обработано подразделов: %d шт, раздач в них %d',
        count($forumsUpdateTime),
        $countTopicsUpdate + $countTopicsRenew
    ));
}
Log::append("Обновление сведений о раздачах завершено за " . Timers::getExecTime('topics_update'));