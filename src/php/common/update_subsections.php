<?php

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/api.php';

use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Module\CloneTable;
use KeepersTeam\Webtlo\Module\LastUpdate;
use KeepersTeam\Webtlo\Module\Topics;

// Обновляем дерево подразделов
include_once dirname(__FILE__) . '/forum_tree.php';

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

// Параметры таблиц.
$tabTime = CloneTable::create('UpdateTime');
// Обновляемые раздачи.
$tabTopicsUpdate = CloneTable::create(
    'Topics',
    ['id', 'ss', 'se', 'st', 'qt', 'ds', 'pt', 'ps', 'ls'],
    'id',
    'Update'
);
// Новые раздачи.
$tabTopicsRenew = CloneTable::create(
    'Topics',
    ['id','ss','na','hs','se','si','st','rg','qt','ds','pt','ps','ls'],
    'id',
    'Renew'
);
// Сиды-Хранители раздач.
$tabKeepers = CloneTable::create('KeepersSeeders', [], 'topic_id');


// время текущего и предыдущего обновления
$currentUpdateTime  = new DateTime();
$previousUpdateTime = new DateTime();

$forumsUpdateTime = [];
$noUpdateForums   = [];
if (isset($cfg['subsections'])) {
    $subsections = array_keys($cfg['subsections']);
    sort($subsections);

    // получим список всех хранителей
    $keepersUserData = $api->getKeepersUserData();
    $keepersUserData = array_filter(array_map(fn ($el) => $el[0] ?? null, $keepersUserData['result']));

    // обновим каждый хранимый подраздел
    foreach ($subsections as $forum_id) {
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
        $topics_data = array_chunk($topics_data['result'], 500, true);

        foreach ($topics_data as $topics_result) {
            // получаем данные о раздачах за предыдущее обновление
            $selectTopics = KeysObject::create(array_keys($topics_result));
            $topics_data_previous = Db::query_database(
                "SELECT id,se,rg,qt,ds FROM Topics WHERE id IN ($selectTopics->keys)",
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

                // Хранители раздачи
                if (!empty($topic_data['keepers'])) {
                    $topicsKeepersFromForum[$topic_id] = $topic_data['keepers'];
                }

                $days_update = 0;
                $sum_updates = 1;
                $sum_seeders = $topic_data['seeders'];

                // запоминаем имеющиеся данные о раздаче в локальной базе
                $previous_data = $topics_data_previous[$topic_id] ?? [];

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

                // Новая или обновлённая раздача
                if (
                    empty($previous_data)
                    || $isTopicDataDelete
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
                        'ps' => $topic_data['topic_poster'],
                        'ls' => $topic_data['seeder_last_seen'],
                    ];
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
                    'ps' => $topic_data['topic_poster'],
                    'ls' => $topic_data['seeder_last_seen'],
                ];

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
                                'keeper_name' => $keepersUserData[$keeperID],
                            ];
                        }
                    }
                }

                // обновление данных в базе о сидах-хранителях
                $tabKeepers->cloneFillChunk($dbTopicsKeepers, 250);
                unset($dbTopicsKeepers);
            }

            unset($topics_data_previous);

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

if ($tabKeepers->cloneCount() > 0) {
    Log::append("Запись в базу данных списка сидов-хранителей...");
    $tabKeepers->moveToOrigin();

    // Удалить ненужные записи.
    Db::query_database(
        "DELETE FROM $tabKeepers->origin WHERE topic_id || keeper_id NOT IN (
            SELECT ks.topic_id || ks.keeper_id
            FROM $tabKeepers->clone tmp
            LEFT JOIN $tabKeepers->origin ks ON tmp.topic_id = ks.topic_id AND tmp.keeper_id = ks.keeper_id
            WHERE ks.topic_id IS NOT NULL
        )"
    );
}

$countTopicsUpdate = $tabTopicsUpdate->cloneCount();
$countTopicsRenew  = $tabTopicsRenew->cloneCount();
if ($countTopicsUpdate > 0 || $countTopicsRenew > 0) {
    // переносим данные в основную таблицу
    $tabTopicsUpdate->moveToOrigin();
    $tabTopicsRenew->moveToOrigin();

    $forums_ids = array_keys($forumsUpdateTime);
    $in = implode(',', $forums_ids);
    Db::query_database(
        "DELETE FROM Topics WHERE id IN (
            SELECT Topics.id FROM Topics
            LEFT JOIN $tabTopicsUpdate->clone tpu ON Topics.id = tpu.id
            LEFT JOIN $tabTopicsRenew->clone tpr ON Topics.id = tpr.id
            WHERE tpu.id IS NULL AND tpr.id IS NULL AND Topics.ss IN ($in)
        )"
    );
    // время последнего обновления для каждого подраздела
    $tabTime->cloneFillChunk($forumsUpdateTime);
    $tabTime->moveToOrigin();
    // Записываем время обновления.
    LastUpdate::setTime(UpdateMark::SUBSECTIONS->value);

    Log::append(sprintf(
        'Обработано хранимых подразделов: %d шт, раздач в них %d',
        count($forumsUpdateTime),
        $countTopicsUpdate + $countTopicsRenew
    ));
}
Log::append("Обновление сведений о раздачах завершено за " . Timers::getExecTime('topics_update'));