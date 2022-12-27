<?php

$starttime = microtime(true);

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/reports.php';

Log::append("Начато обновление списка раздач других хранителей...");

// получение настроек
if (!isset($cfg)) {
    $cfg = get_settings();
}

// проверка настроек
if (empty($cfg['tracker_login'])) {
    throw new Exception("Error: Не указано имя пользователя для доступа к форуму");
}

if (empty($cfg['tracker_paswd'])) {
    throw new Exception("Error: Не указан пароль пользователя для доступа к форуму");
}

// создаём временную таблицу
Db::query_database(
    "CREATE TEMP TABLE KeepersNew AS
    SELECT * FROM Keepers WHERE 0 = 1"
);

// подключаемся к форуму
if (!isset($reports)) {
    $reports = new Reports(
        $cfg['forum_address'],
        $cfg['tracker_login'],
        $cfg['tracker_paswd']
    );
    // применяем таймауты
    $reports->curl_setopts($cfg['curl_setopt']['forum']);
}

$numberForumsScanned = 0;

if (isset($cfg['subsections'])) {
    // получаем данные
    foreach ($cfg['subsections'] as $forum_id => $subsection) {
        $topic_id = $reports->search_topic_id($subsection['na']);

        if (empty($topic_id)) {
            Log::append("Error: Не удалось найти тему со списком для подраздела № $forum_id");
            continue;
        } else {
            $numberForumsScanned++;
        }

        // Log::append("Сканирование подраздела № $forum_id...");

        $keepers = $reports->scanning_viewtopic($topic_id);

        if (!empty($keepers)) {
            foreach ($keepers as &$keeper) {
                if (
                    empty($keeper['topics_ids'])
                    || strcasecmp($cfg['tracker_login'], $keeper['nickname']) === 0
                ) {
                    continue;
                }
                foreach ($keeper['topics_ids'] as $index => $keeperTopicsIDs) {
                    $topics_ids = array_chunk($keeperTopicsIDs, 249);
                    foreach ($topics_ids as $topics_ids) {
                        $select = str_repeat(
                            'SELECT ?,?,?,? UNION ALL ',
                            count($topics_ids) - 1
                        ) . 'SELECT ?,?,?,?';
                        foreach ($topics_ids as $topic_id) {
                            $keepers_topics_ids[] = $topic_id;
                            $keepers_topics_ids[] = $keeper['nickname'];
                            $keepers_topics_ids[] = $keeper['posted'];
                            $keepers_topics_ids[] = $index;
                        }
                        Db::query_database(
                            "INSERT INTO temp.KeepersNew (id,nick,posted,complete) $select",
                            $keepers_topics_ids
                        );
                        unset($keepers_topics_ids);
                        unset($select);
                    }
                }
            }
            unset($keepers);
            unset($keeper);
        }
    }
}
// записываем изменения в локальную базу
$count_keepers = Db::query_database(
    "SELECT COUNT() FROM temp.KeepersNew",
    [],
    true,
    PDO::FETCH_COLUMN
);

if ($count_keepers[0] > 0) {
    Log::append("Просканировано подразделов: " . $numberForumsScanned . " шт.");
    Log::append("Запись в базу данных списка раздач других хранителей...");

    Db::query_database("INSERT INTO Keepers SELECT * FROM temp.KeepersNew");

    Db::query_database(
        "DELETE FROM Keepers WHERE id || nick NOT IN (
            SELECT Keepers.id || Keepers.nick FROM temp.KeepersNew
            LEFT JOIN Keepers ON temp.KeepersNew.id = Keepers.id AND temp.KeepersNew.nick = Keepers.nick
            WHERE Keepers.id IS NOT NULL
        )"
    );
}

$endtime = microtime(true);

Log::append("Обновление списка раздач других хранителей завершено за " . convert_seconds($endtime - $starttime));
