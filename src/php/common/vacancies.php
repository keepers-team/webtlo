<?php

use KeepersTeam\Webtlo\Config\Validate as ConfigValidate;

$starttime = microtime(true);

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/reports.php';

Log::append("Начат процесс формирования вакансий...");

// получение настроек
$cfg = get_settings();
$user = ConfigValidate::checkUser($cfg);

// настройки вакансий
$vacancies = $cfg['vacancies'];

// проверка настроек
if (empty($vacancies['send_topic_id'])) {
    throw new Exception("Error: Не указан send_topic_id");
}

if (empty($vacancies['send_post_id'])) {
    throw new Exception("Error: Не указан send_post_id");
}

// исключить/включить подразделы
$exclude = explode(',', $vacancies['exclude_forums_ids']);
$include = explode(',', $vacancies['include_forums_ids']);

// создаём временную таблицу
Db::query_database("CREATE TEMP TABLE VacanciesKeepers (id INT NOT NULL, posted INT)");

// просканировать все актуальные списки
if ($vacancies['scan_reports']) {
    $reports = new Reports(
        $cfg['forum_address'],
        $cfg['tracker_login'],
        $cfg['tracker_paswd']
    );
    // применяем таймауты
    $reports->curl_setopts($cfg['curl_setopt']['forum']);
    $topics_ids = $reports->scanning_viewforum(1584);
    Log::append("Найдено тем со списками: " . count($topics_ids) . " шт.");
    foreach ($topics_ids as $topic_id) {
        $keepers = $reports->scanning_viewtopic($topic_id, $vacancies['scan_posted_days']);
        if (!empty($keepers)) {
            foreach ($keepers as $keeper) {
                if (empty($keeper['topics_ids'])) {
                    continue;
                }
                $posted = $keeper['posted'];
                foreach ($keeper['topics_ids'] as $index => $keeperTopicsIDs) {
                    $topicsIDs = array_chunk($keeperTopicsIDs, 499);
                    foreach ($topicsIDs as $topicsIDs) {
                        $select = str_repeat('SELECT ?,' . $posted . ' UNION ALL ', count($topicsIDs) - 1) . 'SELECT ?,' . $posted;
                        Db::query_database(
                            "INSERT INTO temp.VacanciesKeepers (id,posted) $select",
                            $topicsIDs
                        );
                        unset($select);
                    }
                    unset($topicsIDs);
                }
            }
        }
        unset($keepers);
        unset($keeper);
    }
    unset($topics_ids);
}

// формируем ср. сиды
for ($i = 0; $i < $vacancies['avg_seeders_period']; $i++) {
    $avg['sum_se'][] = "CASE WHEN d$i IS \"\" OR d$i IS NULL THEN 0 ELSE d$i END";
    $avg['sum_qt'][] = "CASE WHEN q$i IS \"\" OR q$i IS NULL THEN 0 ELSE q$i END";
}
$sum_se = implode('+', $avg['sum_se']);
$sum_qt = implode('+', $avg['sum_qt']);
$avg = "( se * 1. + $sum_se ) / ( qt + $sum_qt )";

// получаем из локальной базы список малосидируемых раздач
$avg_seeders_value = $vacancies['avg_seeders_value'];
$reg_time_seconds = $vacancies['reg_time_seconds'];
$in = str_repeat('?,', count($exclude) - 1) . '?';
$ids = Db::query_database(
    "SELECT ss,si FROM Topics
    LEFT JOIN Seeders ON Seeders.id = Topics.id
    WHERE pt > 0
    AND st IN (0,2,3,8,10)
    AND ss NOT IN ($in)
    AND $avg <= $avg_seeders_value
    AND strftime('%s','now') - rg >= $reg_time_seconds
    AND Topics.id NOT IN (
        SELECT temp.VacanciesKeepers.id FROM temp.VacanciesKeepers
        LEFT JOIN Topics ON Topics.id = temp.VacanciesKeepers.id
        WHERE rg < posted
    )",
    $exclude,
    true,
    PDO::FETCH_COLUMN | PDO::FETCH_GROUP
);

// добавляем "включения"
foreach ($include as $forum_id) {
    if (!empty($forum_id)) {
        $ids[$forum_id] = [];
    }
}

// получаем список всех подразделов
$forums = Db::query_database(
    "SELECT id, name, quantity, size FROM Forums WHERE quantity > 0",
    [],
    true,
    PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE
);

// разбираем названия подразделов
$forums = array_map(function ($a) {
    return array_map(function ($b) {
        return preg_match('/^[0-9]*$/', $b) ? $b : explode(' » ', $b);
    }, $a);
}, $forums);

// всего вакантных раздач
$total_count_vacant_topics = 0;
$total_size_vacant_topics = 0;

// приводим данные к требуемому виду
foreach ($ids as $forum_id => $tor_sizes) {
    if (!isset($forums[$forum_id])) {
        continue;
    }
    $title = $forums[$forum_id]['name'];
    $count_vacant_topics = count($tor_sizes);
    $size_vacant_topics = array_sum($tor_sizes);
    switch (count($title)) {
        case 2:
            $topics[$title[0]][$title[1]]['root']['id'] = $forum_id;
            $topics[$title[0]][$title[1]]['root']['qt'] = $count_vacant_topics;
            $topics[$title[0]][$title[1]]['root']['si'] = $size_vacant_topics;
            $topics[$title[0]][$title[1]]['root']['sum_qt'] = $forums[$forum_id]['quantity'];
            $topics[$title[0]][$title[1]]['root']['sum_si'] = $forums[$forum_id]['size'];
            break;
        case 3:
            $topics[$title[0]][$title[1]][$title[2]]['id'] = $forum_id;
            $topics[$title[0]][$title[1]][$title[2]]['qt'] = $count_vacant_topics;
            $topics[$title[0]][$title[1]][$title[2]]['si'] = $size_vacant_topics;
            $topics[$title[0]][$title[1]][$title[2]]['sum_qt'] = $forums[$forum_id]['quantity'];
            $topics[$title[0]][$title[1]][$title[2]]['sum_si'] = $forums[$forum_id]['size'];
            break;
    }
    $total_count_vacant_topics += $count_vacant_topics;
    $total_size_vacant_topics += $size_vacant_topics;
    unset($count_vacant_topics);
    unset($size_vacant_topics);
}
unset($forums);
unset($ids);

$total_size_vacant_topics = convert_bytes($total_size_vacant_topics);

Log::append("Всего вакантных раздач: " . $total_count_vacant_topics . " шт.");
Log::append("Объём вакантных раздач: " . $total_size_vacant_topics);

// сортируем по названию корневого раздела
uksort($topics, function ($a, $b) {
    return strnatcasecmp($a, $b);
});

$output = "";
$forum_pattern = '[spoiler="%s | %s шт. | %s"]%s[/spoiler]';
$sub_forum_pattern = '[url=tracker.php?f=%s&tm=-1&o=10&s=1&oop=1]' .
    '[color=%s][u]%s[/u][/color][/url] — %s шт. (%s)[br]';

// формируем список вакансий
foreach ($topics as $forum => &$sub_forums) {
    // сортируем по название раздела
    uksort($sub_forums, function ($a, $b) {
        return strnatcasecmp($a, $b);
    });
    $qt = $si = 0;
    $forum_list = "";
    foreach ($sub_forums as $sub_forum => &$titles) {
        // сортируем по названию подраздела
        uksort($titles, function ($a, $b) {
            return strnatcasecmp($a, $b);
        });
        $sub_forum_list = "";
        foreach ($titles as $title => $value) {
            $qt += $value['qt'];
            $si += $value['si'];
            if (!in_array($value['id'], $include)) {
                if (preg_match('/DVD|HD/i', $title)) {
                    $size = pow(1024, 4);
                    if ($value['si'] < $size) {
                        $color = $value['si'] >= $size * 3 / 4 ? 'orange' : 'green';
                    } else {
                        $color = 'red';
                    }
                } else {
                    $size = pow(1024, 4) / 2;
                    if (
                        $value['qt'] < 1000
                        && $value['si'] < $size
                    ) {
                        $color = $value['qt'] >= 500 || $value['si'] >= $size / 2 ? 'orange' : 'green';
                    } else {
                        $color = 'red';
                    }
                }
                if ($color == 'green') {
                    continue;
                }
            }
            $subject = $title == 'root' ? $sub_forum . ' (корень раздела)' : $title;
            if (in_array($value['id'], $include)) {
                $sub_forum_list .= sprintf(
                    $sub_forum_pattern,
                    $value['id'],
                    'red',
                    $subject,
                    '-',
                    '-'
                );
            } else {
                $sub_forum_list .= sprintf(
                    $sub_forum_pattern,
                    $value['id'],
                    $color,
                    $subject,
                    $value['qt'],
                    convert_bytes($value['si'])
                );
            }
        }
        if (!empty($sub_forum_list)) {
            $forum_list .= '[br][b]' . $sub_forum . '[/b][br][br]' . $sub_forum_list;
        }
    }
    if (!empty($forum_list)) {
        $output .= sprintf(
            $forum_pattern,
            $forum,
            $qt,
            convert_bytes($si),
            $forum_list
        );
    }
}

// отправляем вакансии на форум
if (!empty($output)) {
    if (!isset($reports)) {
        $reports = new Reports(
            $cfg['forum_address'],
            $user
        );
        // применяем таймауты
        $reports->curl_setopts($cfg['curl_setopt']['forum']);
    }
    $output = 'Актуально на: [b]' . date('d.m.Y') . '[/b][br]' .
        'Всего вакантных раздач: [b]' . $total_count_vacant_topics . ' шт.[/b][br]' .
        'Объём вакантных раздач: [b]' . $total_size_vacant_topics . '[/b][br]' .
        $output;
    $reports->send_message(
        'editpost',
        $output,
        $vacancies['send_topic_id'],
        $vacancies['send_post_id']
    );
}

$endtime = microtime(true);

Log::append("Формирование вакансий завершено за " . convert_seconds($endtime - $starttime));
