<?php

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/reports.php';

Timers::start('update_keepers');

// получение настроек
if (!isset($cfg)) {
    $cfg = get_settings();
}

// проверка настроек
if (empty($cfg['tracker_login'])) {
    throw new Exception('Error: Не указано имя пользователя для доступа к форуму');
}

if (empty($cfg['tracker_paswd'])) {
    throw new Exception('Error: Не указан пароль пользователя для доступа к форуму');
}

if (isset($checkEnabledCronAction)) {
    $checkEnabledCronAction = $cfg['automation'][$checkEnabledCronAction] ?? -1;
    if ($checkEnabledCronAction == 0) {
        throw new Exception('Notice: Автоматическое обновление списков других хранителей отключено в настройках.');
    }
}


// Подключаемся к форуму.
if (!isset($reports)) {
    $reports = new Reports(
        $cfg['forum_address'],
        $cfg['tracker_login'],
        $cfg['tracker_paswd']
    );
    // применяем таймауты
    $reports->curl_setopts($cfg['curl_setopt']['forum']);
}
Log::append('Info: Начато обновление списков раздач хранителей...');

if (!$reports->check_access())
{
    Log::append('Error: Нет доступа к подфоруму хранителей. Обновление списков невозможно. ' .
        'Если вы Кандидат, то ожидайте включения в основную группу.');
    return;
}

$numberForumsScanned = 0;

$keeperIds      = [];
$noUpdateForums = [];

// Параметры таблиц.
$KL = (object)[
    'table'   => 'KeepersLists',
    'temp'    => Db::temp_copy_table('KeepersLists'),
    'primary' => 'topic_id',
    'keys'    => ['topic_id', 'keeper_id', 'keeper_name', 'posted', 'complete'],
];
$FP = (object)[
    'table'   => 'ForumsOptions',
    'temp'    => Db::temp_copy_table('ForumsOptions'),
    'primary' => 'forum_id',
];

$forumsParams = [];
if (isset($cfg['subsections'])) {
    // получаем данные
    foreach ($cfg['subsections'] as $forum_id => $subsection) {
        // Ид обновления хранителей подраздела.
        $updateForumId = 100000 + $forum_id;
        if (!check_update_available($updateForumId)) {
            $noUpdateForums[] = $forum_id;
            continue;
        }

        // ид темы со списками хранителей.
        $forum_topic_id = $reports->search_topic_id($subsection['na']);

        if (empty($forum_topic_id)) {
            Log::append("Error: Не удалось найти тему со списками для подраздела № $forum_id.");
            continue;
        } else {
            $numberForumsScanned++;
        }

        // Ищем списки хранимого другими хранителями.
        $keepers = $reports->scanning_viewtopic($forum_topic_id);
        if (!empty($keepers)) {

            $userPosts = [];
            foreach ($keepers as $keeper) {
                if (empty($keeper['topics_ids'])) {
                    continue;
                }
                $keeperIds[] = $keeper['user_id'];
                if ($keeper['user_id'] == $cfg['user_id']) {
                    $userPosts[] = $keeper['post_id'];
                }

                foreach ($keeper['topics_ids'] as $complete => $keeperTopicsIDs) {
                    $topics_ids_chunks = array_chunk($keeperTopicsIDs, 249);
                    foreach ($topics_ids_chunks as $topics_ids) {
                        $keepers_topics_ids = [];
                        foreach ($topics_ids as $topic_id) {
                            $keepers_topics_ids[] = array_combine($KL->keys, [
                                $topic_id,
                                $keeper['user_id'],
                                $keeper['nickname'],
                                $keeper['posted'],
                                $complete,
                            ]);
                        }

                        Db::table_insert_dataset($KL->temp, $keepers_topics_ids, $KL->primary);
                        unset($topics_ids, $keepers_topics_ids, $select);
                    }
                    unset($complete, $keeperTopicsIDs, $topics_ids_chunks);
                }
                unset($keeper);
            }

            // Сохраним данных о своих постах в теме по подразделу.
            $forumsParams[$forum_id] = [
                'forum_id'       => $forum_id,
                'topic_id'       => $forum_topic_id,
                'author_id'      => $keepers[0]['user_id'] ?? 0,
                'author_name'    => $keepers[0]['nickname'] ?? '',
                'author_post_id' => $keepers[0]['post_id'] ?? 0,
                'post_ids'       => json_encode($userPosts),
            ];

            set_last_update_time($updateForumId);
            unset($keepers, $updateForumId);
        }
    }
}

// Записываем дополнительные данные о хранимых подразделах, в т.ч. ид своих постов.
if (count($forumsParams)) {
    Db::table_insert_dataset($FP->temp, $forumsParams, $FP->primary);

    // Переносим данные из временной таблицы в основную.
    Db::table_insert_temp($FP->table, $FP->temp);

    // TODO to function
    // Удаляем неактуальные записи подразделов.
    Db::query_database(
        "DELETE FROM $FP->table WHERE $FP->primary NOT IN (
            SELECT upd.$FP->primary
            FROM $FP->temp AS tmp
            LEFT JOIN $FP->table AS upd ON tmp.$FP->primary = upd.$FP->primary
            WHERE upd.$FP->primary IS NOT NULL
        )"
    );
}

if (count($noUpdateForums)) {
    sort($noUpdateForums);
    Log::append(sprintf(
        'Notice: Обновление списков хранителей не требуется для подразделов №№ %s.',
        implode(',', $noUpdateForums)
    ));
}

// записываем изменения в локальную базу
$count_kept_topics = Db::select_count($KL->temp);
if ($count_kept_topics > 0) {
    Log::append(sprintf(
        'Просканировано подразделов: %d шт, хранителей: %d, хранимых раздач: %d шт.',
        $numberForumsScanned,
        count(array_unique($keeperIds)),
        $count_kept_topics
    ));
    Log::append('Запись в базу данных списков раздач хранителей...');

    // Переносим данные из временной таблицы в основную.
    Db::table_insert_temp($KL->table, $KL->temp);

    // Удаляем неактуальные записи списков.
    Db::query_database(
        "DELETE FROM $KL->table WHERE topic_id || keeper_id NOT IN (
            SELECT upd.topic_id || upd.keeper_id
            FROM $KL->temp AS tmp
            LEFT JOIN $KL->table AS upd ON tmp.topic_id = upd.topic_id AND tmp.keeper_id = upd.keeper_id
            WHERE upd.topic_id IS NOT NULL
        )"
    );

    Log::append('Info: Обновление списков раздач хранителей завершено за ' . Timers::getExecTime('update_keepers'));
}
