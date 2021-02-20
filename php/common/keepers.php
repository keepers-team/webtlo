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
        $topicID = $reports->search_topic_id($subsection['na']);

        if (empty($topicID)) {
            Log::append("Error: Не удалось найти тему со списком для подраздела № $forum_id");
            continue;
        } else {
            $numberForumsScanned++;
        }

        // Log::append("Сканирование подраздела № $forum_id...");

        $keepers = $reports->scanning_viewtopic($topicID);

        if (!empty($keepers)) {
            foreach ($keepers as &$keeper) {
                if (
                    empty($keeper['topics_ids'])
                    || strcasecmp($cfg['tracker_login'], $keeper['nickname']) === 0
                ) {
                    continue;
                }
                foreach ($keeper['topics_ids'] as $index => $keeperTopicsIDs) {
                    $topicsIDsChunks = array_chunk($keeperTopicsIDs, 199);
                    foreach ($topicsIDsChunks as $topicsIDs) {
                        $select = str_repeat(
                                'SELECT ?,?,?,?,? UNION ALL ',
                                count($topicsIDs) - 1
                            ) . 'SELECT ?,?,?,?,?';
                        foreach ($topicsIDs as $topicID) {
                            $keepersTopicsIDs[] = $topicID;
                            $keepersTopicsIDs[] = $keeper['nickname'];
                            $keepersTopicsIDs[] = $keeper['posted'];
                            $keepersTopicsIDs[] = $index;
                            $keepersTopicsIDs[] = null;
                        }
                        Db::query_database(
                            "INSERT INTO temp.KeepersNew (id,nick,posted,complete,seeding) $select",
                            $keepersTopicsIDs
                        );
                        unset($keepersTopicsIDs);
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
    array(),
    true,
    PDO::FETCH_COLUMN
);

if ($count_keepers[0] > 0) {
    Log::append("Просканировано подразделов: " . $numberForumsScanned . " шт.");
    Log::append("Запись в базу данных списка раздач других хранителей...");

    Db::query_database(
        "DELETE FROM Keepers WHERE id || nick NOT IN (
            SELECT Keepers.id || Keepers.nick FROM temp.KeepersNew
            LEFT JOIN Keepers ON temp.KeepersNew.id = Keepers.id AND temp.KeepersNew.nick = Keepers.nick
            WHERE Keepers.id IS NOT NULL
        ) AND posted IS NOT NULL"
    );

    Db::query_database(
        "INSERT INTO Keepers 
            SELECT t.id, t.nick, t.posted, t.complete, k.seeding FROM temp.KeepersNew AS t
            LEFT JOIN Keepers AS k ON k.id = t.id AND k.nick = t.nick
            UNION ALL
            SELECT k.id, k.nick, t.posted, k.complete, k.seeding FROM Keepers AS k
            LEFT JOIN temp.KeepersNew AS t ON k.id = t.id AND k.nick = t.nick"
    );
}

$endtime = microtime(true);

Log::append("Обновление списка раздач других хранителей завершено за " . convert_seconds($endtime - $starttime));
