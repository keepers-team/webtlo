<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Config\SubForums;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Storage\KeysObject;

$statistics_result = [
    'tbody' => '',
    'tfoot' => '',
];

try {
    $app = App::create();
    $db  = $app->getDataBase();

    /** @var SubForums $subsections хранимые подразделы */
    $subsections = $app->get(SubForums::class);

    if (!$subsections->count()) {
        throw new Exception('В настройках не найдены хранимые подразделы');
    }

    $statistics      = [];
    $forumsIDsChunks = array_chunk($subsections->ids, 499);

    $days30 = 30 * 24 * 60 * 60; // seconds
    foreach ($forumsIDsChunks as $forumsIDs) {
        $forumChunk = KeysObject::create($forumsIDs);

        $sql = '
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
                WHERE t.forum_id IN (' . $forumChunk->keys . ")
                    AND t.keeping_priority IN (1,2)
                    AND Torrents.info_hash IS NULL
                    AND t.reg_time <= unixepoch() - $days30
                    AND (k.topic_id IS NULL OR k.posted <= unixepoch() - $days30)
            ) AS seeds ON seeds.forum_id = f.id
            WHERE f.id IN (" . $forumChunk->keys . ')
            GROUP BY f.id, f.name
            ORDER BY LOWER(f.name)
        ';

        $data = $db->query(
            sql  : $sql,
            param: array_merge($forumChunk->values, $forumChunk->values),
        );

        $statistics = array_merge($statistics, $data);
    }

    // 10
    $tfoot = [
        [0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        [0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
    ];

    $size4 = (int) pow(1024, 4);

    $tbody = implode('', array_map(function($e) use ($size4, &$tfoot) {
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
        $tfoot[1][4] += $e['Count10'] + $e['Count5'] + $e['Count0'];
        $tfoot[1][5] += $e['Size10'] + $e['Size5'] + $e['Size0'];
        $tfoot[1][6] += $e['Count15'] + $e['Count10'] + $e['Count5'] + $e['Count0'];
        $tfoot[1][7] += $e['Size15'] + $e['Size15'] + $e['Size10'] + $e['Size0'];
        $tfoot[1][8] += $e['quantity'];
        $tfoot[1][9] += $e['size'];

        // состояние раздела (цвет)
        $state = '';

        $sizeSum50  = (int) ($e['Size5'] + $e['Size0']);
        $countSum50 = (int) ($e['Count5'] + $e['Count0']);
        if (preg_match('/DVD|HD/', $e['forum_name'])) {
            if ($sizeSum50 < $size4) {
                if ($sizeSum50 >= (int) ($size4 * 3 / 4)) {
                    $state = 'ui-state-highlight';
                }
            } else {
                $state = 'ui-state-error';
            }
        } else {
            $size = $size4 / 2;
            if ($countSum50 < 1000 && $sizeSum50 < $size) {
                if ($countSum50 >= 500 || $sizeSum50 >= $size / 2) {
                    $state = 'ui-state-highlight';
                }
            } else {
                $state = 'ui-state-error';
            }
        }

        // байты
        $e['Size0']  = Helper::convertBytes((int) $e['Size0']);
        $e['Size5']  = Helper::convertBytes((int) $e['Size5']);
        $e['Size10'] = Helper::convertBytes((int) $e['Size10']);
        $e['Size15'] = Helper::convertBytes((int) $e['Size15']);
        $e['size']   = Helper::convertBytes((int) $e['size']);

        $e = implode('', array_map(fn($col) => "<td>$col</td>", $e));

        return "<tr class=\"$state\">$e</tr>";
    }, $statistics));

    // всего/всего (от нуля)
    $tfoot = array_map(function($row) {
        foreach ([1, 3, 5, 7, 9] as $i) {
            // байты
            $row[$i] = Helper::convertBytes((int) $row[$i]);
        }

        return implode('', array_map(fn($col) => "<th>$col</th>", $row));
    }, $tfoot);

    $tfoot = sprintf(
        '<tr><th colspan="2">Всего</th>%s</tr>',
        implode('</tr><tr><th colspan="2">Всего (от нуля)</th>', $tfoot)
    );

    $statistics_result = [
        'tbody' => $tbody,
        'tfoot' => $tfoot,
    ];
} catch (Exception $e) {
    $statistics_result['tbody'] = '<tr><th colspan="12">' . $e->getMessage() . '</th></tr>';
}

echo json_encode($statistics_result, JSON_UNESCAPED_UNICODE);
