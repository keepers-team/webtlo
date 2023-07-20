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
Log::append('Начато обновление списков раздач хранителей...');

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

if (isset($cfg['subsections'])) {
    // получаем данные
    foreach ($cfg['subsections'] as $forum_id => $subsection) {
        $updateForumId = 100000 + $forum_id;
        if (!check_update_available($updateForumId)) {
            $noUpdateForums[] = $forum_id;
            continue;
        }

        $topic_id = $reports->search_topic_id($subsection['na']);

        if (empty($topic_id)) {
            Log::append("Error: Не удалось найти тему со списками для подраздела № $forum_id.");
            continue;
        } else {
            $numberForumsScanned++;
        }

        // Ищем списки хранимого другими хранителями.
        $keepers = $reports->scanning_viewtopic($topic_id);
        if (!empty($keepers)) {
            foreach ($keepers as $keeper) {
                if (empty($keeper['topics_ids'])) {
                    continue;
                }
                $keeperIds[] = $keeper['user_id'];

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

            set_last_update_time($updateForumId);
            unset($keepers, $updateForumId);
        }
    }
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

    // Удаляем неактуальные записи.
    Db::query_database(
        "DELETE FROM $KL->table WHERE topic_id || keeper_id NOT IN (
            SELECT upd.topic_id || upd.keeper_id
            FROM $KL->temp AS tmp
            LEFT JOIN $KL->table AS upd ON tmp.topic_id = upd.topic_id AND tmp.keeper_id = upd.keeper_id
            WHERE upd.topic_id IS NOT NULL
        )"
    );

    Log::append('Обновление списков раздач хранителей завершено за ' . Timers::getExecTime('update_keepers'));
}
