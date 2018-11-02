<?php

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/reports.php';

// исключить/включить подразделы
// через запятую, без пробелов
$include = "";
$exclude = "";

// период ср. сидов
$avg_period = 14;

$exclude = explode(',', $exclude);
$include = explode(',', $include);

// получаем настройки
$cfg = get_settings();

// создаём временную таблицу
Db::query_database("CREATE TEMP TABLE Keepers2 (id INT NOT NULL)");

// просканировать все актуальные списки
$reports = new Reports(
    $cfg['forum_url'],
    $cfg['tracker_login'],
    $cfg['tracker_paswd']
);
$topics_ids = $reports->scanning_viewforum(1584);
foreach ($topics_ids as $topic_id) {
    $keepers = $reports->scanning_viewtopic($topic_id, 30);
    if (!empty($keepers)) {
        foreach ($keepers as &$keeper) {
            if (empty($keeper['topics_ids'])) {
                continue;
            }
            $keeper['topics_ids'] = array_chunk($keeper['topics_ids'], 500);
            foreach ($keeper['topics_ids'] as $keeper_topics_ids) {
                $select = str_repeat('SELECT ? UNION ALL ', count($keeper_topics_ids) - 1) . 'SELECT ?';
                Db::query_database(
                    "INSERT INTO temp.Keepers2 (id) $select",
                    $keeper_topics_ids
                );
                unset($select);
            }
        }
    }
    unset($keepers);
    unset($keeper);
}
unset($topics_ids);
unset($reports);

// формируем ср. сиды
for ($i = 0; $i < $avg_period; $i++) {
    $avg['sum_se'][] = "CASE WHEN d$i IS \"\" OR d$i IS NULL THEN 0 ELSE d$i END";
    $avg['sum_qt'][] = "CASE WHEN q$i IS \"\" OR q$i IS NULL THEN 0 ELSE q$i END";
}
$sum_se = implode('+', $avg['sum_se']);
$sum_qt = implode('+', $avg['sum_qt']);
$avg = "( se * 1. + $sum_se ) / ( qt + $sum_qt )";

// получаем из локальной базы список малосидируемых раздач
$in = str_repeat('?,', count($exclude) - 1) . '?';
$ids = Db::query_database(
    "SELECT ss,si FROM Topics
    LEFT JOIN Seeders ON Seeders.id = Topics.id
    WHERE st IN (0,2,3,8,10) AND ss NOT IN ($in) AND $avg <= 0.5
    AND strftime('%s','now') - rg >= 2592000
    AND Topics.id NOT IN (SELECT id FROM temp.Keepers2)",
    $exclude,
    true,
    PDO::FETCH_COLUMN | PDO::FETCH_GROUP
);

// добавляем "включения"
foreach ($include as $forum_id) {
    if (!empty($forum_id)) {
        $ids[$forum_id] = array();
    }
}

// получаем список всех подразделов
$forums = Db::query_database(
    "SELECT id,na,qt,si FROM Forums WHERE qt > 0",
    array(),
    true,
    PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE
);

// разбираем названия подразделов
$forums = array_map(function ($a) {
    return array_map(function ($b) {
        return preg_match('/^[0-9]*$/', $b) ? $b : explode(' » ', $b);
    }, $a);
}, $forums);

// приводим данные к требуемому виду
foreach ($ids as $forum_id => $tor_sizes) {
    if (!isset($forums[$forum_id])) {
        continue;
    }
    $title = $forums[$forum_id]['na'];
    switch (count($title)) {
        case 2:
            $topics[$title[0]][$title[1]]['root']['id'] = $forum_id;
            $topics[$title[0]][$title[1]]['root']['qt'] = count($tor_sizes);
            $topics[$title[0]][$title[1]]['root']['si'] = array_sum($tor_sizes);
            $topics[$title[0]][$title[1]]['root']['sum_qt'] = $forums[$forum_id]['qt'];
            $topics[$title[0]][$title[1]]['root']['sum_si'] = $forums[$forum_id]['si'];
            break;
        case 3:
            $topics[$title[0]][$title[1]][$title[2]]['id'] = $forum_id;
            $topics[$title[0]][$title[1]][$title[2]]['qt'] = count($tor_sizes);
            $topics[$title[0]][$title[1]][$title[2]]['si'] = array_sum($tor_sizes);
            $topics[$title[0]][$title[1]][$title[2]]['sum_qt'] = $forums[$forum_id]['qt'];
            $topics[$title[0]][$title[1]][$title[2]]['sum_si'] = $forums[$forum_id]['si'];
            break;
    }
}
unset($forums);
unset($ids);

// сортируем по названию корневого раздела
uksort($topics, function ($a, $b) {
    return strnatcasecmp($a, $b);
});

$output = "";
$forum_pattern = '[spoiler="%s | %s шт. | %s"]%s[/spoiler]\n';
$sub_forum_pattern = '[url=tracker.php?f=%s&tm=-1&o=10&s=1&oop=1]' .
    '[color=%s][u]%s[/u][/color][/url] - %s шт. (%s)\n';

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
            $forum_list .= '\n[b]' . $sub_forum . '[/b]\n\n' . $sub_forum_list;
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
