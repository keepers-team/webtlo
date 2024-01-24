<?php

use KeepersTeam\Webtlo\DTO\KeysObject;

$statistics_result = [
    'tbody' => '',
    'tfoot' => '',
];
try {
    include_once dirname(__FILE__) . '/../common.php';

    // получение настроек
    $cfg = get_settings();

    if (empty($cfg['subsections'])) {
        throw new Exception("Не выбраны хранимые подразделы");
    }

    $statistics = [];
    $forumsIDsChunks = array_chunk(array_keys($cfg['subsections']), 499);

    $days30 = 30 * 24 * 60 * 60; // seconds
    foreach ($forumsIDsChunks as $forumsIDs) {
        $forumChunk = KeysObject::create($forumsIDs);

        $sql = "
            SELECT
            f.id AS forum_id,
            f.name AS forum_name,
            COUNT(CASE WHEN seeds.se = 0 THEN 1 END) AS Count0,
            SUM(CASE WHEN seeds.se = 0 THEN seeds.size ELSE 0 END) AS Size0,
            COUNT(CASE WHEN seeds.se > 0 AND seeds.se <= 0.5 THEN 1 END) AS Count5,
            SUM(CASE WHEN seeds.se > 0 AND seeds.se <= 0.5 THEN seeds.size ELSE 0 END) AS Size5,
            COUNT(CASE WHEN seeds.se > 0.5 AND seeds.se <= 1.0 THEN 1 END) AS Count10,
            SUM(CASE WHEN seeds.se > 0.5 AND seeds.se <= 1.0 THEN seeds.size ELSE 0 END) AS Size10,
            COUNT(CASE WHEN seeds.se > 1.0 AND seeds.se <= 1.5 THEN 1 END) AS Count15,
            SUM(CASE WHEN seeds.se > 1.0 AND seeds.se <= 1.5 THEN seeds.size ELSE 0 END) AS Size15,
            f.quantity,
            f.size
            FROM Forums f
            LEFT JOIN (
                SELECT
                    t.id,
                    t.size,
                    t.status,
                    t.forum_id,
                    (CAST((IFNULL(t.seeders, 0)+IFNULL(s.d0 , 0)+IFNULL(s.d1 , 0)+IFNULL(s.d2 , 0)+IFNULL(s.d3 , 0)+IFNULL(s.d4 , 0)+IFNULL(s.d5 , 0)+IFNULL(s.d6 , 0)+IFNULL(s.d7 , 0)+IFNULL(s.d8 , 0)+IFNULL(s.d9 , 0)+
                    IFNULL(s.d10, 0)+IFNULL(s.d11, 0)+IFNULL(s.d12, 0)+IFNULL(s.d13, 0)) as FLOAT)) /
                    ((IFNULL(t.seeders_updates_today, 0)+IFNULL(s.q0 , 0)+IFNULL(s.q1 , 0)+IFNULL(s.q2 , 0)+IFNULL(s.q3 , 0)+IFNULL(s.q4 , 0)+IFNULL(s.q5 , 0)+IFNULL(s.q6 , 0)+IFNULL(s.q7 , 0)+IFNULL(s.q8 , 0)+IFNULL(s.q9 , 0)+
                    IFNULL(s.q10, 0)+IFNULL(s.q11, 0)+IFNULL(s.q12, 0)+IFNULL(s.q13, 0))) AS se
                FROM Topics t
                LEFT JOIN Seeders s ON t.id = s.id
                LEFT JOIN Torrents ON t.info_hash = Torrents.info_hash
                LEFT JOIN (SELECT topic_id, MAX(posted) as posted FROM KeepersLists WHERE complete = 1 GROUP BY topic_id) k ON t.id = k.topic_id
                WHERE t.forum_id IN (" . $forumChunk->keys . ")
                    AND t.keeping_priority IN (1,2)
                    AND Torrents.info_hash IS NULL
                    AND t.reg_time <= unixepoch() - $days30
                    AND (k.topic_id IS NULL OR k.posted <= unixepoch() - $days30)
            ) AS seeds ON seeds.forum_id = f.id
            WHERE f.id IN (" . $forumChunk->keys . ")
            GROUP BY f.id, f.name
            ORDER BY LOWER(f.name)
        ";

        $data = Db::query_database(
            $sql,
            array_merge($forumChunk->values, $forumChunk->values),
            true
        );

        $statistics = array_merge($statistics, $data);
    }

    // 10
    $tfoot = [
        [0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        [0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
    ];

    $tbody = implode("", array_map(function ($e) use (&$tfoot) {
        // всего
        $tfoot[0][0] += $e['Count0'];
        $tfoot[0][1] += $e['Size0'];
        $tfoot[0][2] += $e['Count5'];
        $tfoot[0][3] += $e['Size5'];
        $tfoot[0][4] += $e['Count10'];
        $tfoot[0][5] += $e['Size10'];
        $tfoot[0][6] += $e['Count15'];
        $tfoot[0][7] += $e['Size15'];
        $tfoot[0][8] += $e['quantity'];
        $tfoot[0][9] += $e['size'];
        // всего (от нуля)
        $tfoot[1][0] += $e['Count0'];
        $tfoot[1][1] += $e['Size0'];
        $tfoot[1][2] += $e['Count5'] + $e['Count0'];
        $tfoot[1][3] += $e['Size5'] + $e['Size0'];
        $tfoot[1][4] += $e['Count10'] + $e['Count0'] + $e['Count5'];
        $tfoot[1][5] += $e['Size10'] + $e['Size0'] + $e['Size5'];
        $tfoot[1][6] += $e['Count15'] + $e['Count0'] + $e['Count5'] + $e['Count10'];
        $tfoot[1][7] += $e['Size15'] + $e['Size0'] + $e['Size5'] + $e['Size10'];
        $tfoot[1][8] += $e['quantity'];
        $tfoot[1][9] += $e['size'];

        // состояние раздела (цвет)
        $state = '';
        if (preg_match('/DVD|HD/', $e['forum_name'])) {
            $size = pow(1024, 4);
            if ($e['Size5'] + $e['Size0'] < $size) {
                if ($e['Size5'] + $e['Size0'] >= $size * 3 / 4) {
                    $state = 'ui-state-highlight';
                }
            } else {
                $state = 'ui-state-error';
            }
        } else {
            $size = pow(1024, 4) / 2;
            if (
                $e['Count5'] + $e['Count0'] < 1000
                && $e['Size5'] + $e['Size0'] < $size
            ) {
                if (
                    $e['Count5'] + $e['Count0'] >= 500
                    || $e['Size5'] + $e['Size0'] >= $size / 2
                ) {
                    $state = 'ui-state-highlight';
                }
            } else {
                $state = 'ui-state-error';
            }
        }
        // байты
        $e['Size0']  = convert_bytes($e['Size0']);
        $e['Size5']  = convert_bytes($e['Size5']);
        $e['Size10'] = convert_bytes($e['Size10']);
        $e['Size15'] = convert_bytes($e['Size15']);
        $e['size']   = convert_bytes($e['size']);
        $e = implode("", array_map(function ($e) {
            return "<td>$e</td>";
        }, $e));
        return "<tr class=\"$state\">$e</tr>";
    }, $statistics));

    // всего/всего (от нуля)
    $tfoot = "<tr><th colspan=\"2\">Всего</th>" . implode("</tr><tr><th colspan=\"2\">Всего (от нуля)</th>", array_map(function ($e) {
        // байты
        $e[1] = convert_bytes($e[1]);
        $e[3] = convert_bytes($e[3]);
        $e[5] = convert_bytes($e[5]);
        $e[7] = convert_bytes($e[7]);
        $e[9] = convert_bytes($e[9]);
        $e = implode("", array_map(function ($e) {
            return "<th>$e</th>";
        }, $e));
        return $e;
    }, $tfoot)) . "</tr>";

    $statistics_result = [
        'tbody' => $tbody,
        'tfoot' => $tfoot,
    ];
} catch (Exception $e) {
    $statistics_result['tbody'] = '<tr><th colspan="12">' . $e->getMessage() . '</th></tr>';
}

echo json_encode($statistics_result, JSON_UNESCAPED_UNICODE);
