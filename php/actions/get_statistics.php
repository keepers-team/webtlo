<?php

try {

    include_once dirname(__FILE__) . '/../common.php';

    // получение настроек
    $cfg = get_settings();

    if (empty($cfg['subsections'])) {
        throw new Exception("Не выбраны хранимые подразделы");
    }

    foreach ($cfg['subsections'] as $forum_id => $subsection) {

        $request = "SELECT
                f.id AS id,
                f.na AS na,
                COUNT(CASE WHEN seeds.se = 0 THEN 1 ELSE NULL END) AS Count0,
                SUM(CASE WHEN seeds.se = 0 THEN seeds.si ELSE 0 END) AS Size0,
                COUNT(CASE WHEN seeds.se > 0 AND seeds.se <= 0.5 THEN 1 ELSE NULL END) AS Count5,
                SUM(CASE WHEN seeds.se > 0 AND seeds.se <= 0.5 THEN seeds.si ELSE 0 END) AS Size5,
                COUNT(CASE WHEN seeds.se > 0.5 AND seeds.se <= 1.0 THEN 1 ELSE NULL END) AS Count10,
                SUM(CASE WHEN seeds.se > 0.5 AND seeds.se <= 1.0 THEN seeds.si ELSE 0 END) AS Size10,
                COUNT(CASE WHEN seeds.se > 1.0 AND seeds.se <= 1.5 THEN 1 ELSE NULL END) AS Count15,
                SUM(CASE WHEN seeds.se > 1.0 AND seeds.se <= 1.5 THEN seeds.si ELSE 0 END) AS Size15,
                f.qt AS qt,
                f.si AS si
            FROM (
                SELECT
                    t.id,
                    t.si,
                    t.st,
                    t.ss,
                    (CAST((IFNULL(t.se, 0)+IFNULL(s.d0 , 0)+IFNULL(s.d1 , 0)+IFNULL(s.d2 , 0)+IFNULL(s.d3 , 0)+IFNULL(s.d4 , 0)+IFNULL(s.d5 , 0)+IFNULL(s.d6 , 0)+IFNULL(s.d7 , 0)+IFNULL(s.d8 , 0)+IFNULL(s.d9 , 0)+
                    IFNULL(s.d10, 0)+IFNULL(s.d11, 0)+IFNULL(s.d12, 0)+IFNULL(s.d13, 0)) as FLOAT)) /
                    ((IFNULL(t.qt, 0)+IFNULL(s.q0 , 0)+IFNULL(s.q1 , 0)+IFNULL(s.q2 , 0)+IFNULL(s.q3 , 0)+IFNULL(s.q4 , 0)+IFNULL(s.q5 , 0)+IFNULL(s.q6 , 0)+IFNULL(s.q7 , 0)+IFNULL(s.q8 , 0)+IFNULL(s.q9 , 0)+
                    IFNULL(s.q10, 0)+IFNULL(s.q11, 0)+IFNULL(s.q12, 0)+IFNULL(s.q13, 0))) AS se
                FROM Topics t
                LEFT JOIN Seeders s ON t.id = s.id
                WHERE ss = :ss
                AND strftime('%s', 'now') - rg >= 2592000
            ) AS seeds
            INNER JOIN Forums f ON seeds.ss = f.id";
        $data = Db::query_database(
            $request,
            array('ss' => $forum_id),
            true
        );

        $statistics[$forum_id] = $data[0];

    }

    // 10
    $tfoot = array(
        array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0),
        array(0, 0, 0, 0, 0, 0, 0, 0, 0, 0),
    );

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
        $tfoot[0][8] += $e['qt'];
        $tfoot[0][9] += $e['si'];
        // всего (от нуля)
        $tfoot[1][0] += $e['Count0'];
        $tfoot[1][1] += $e['Size0'];
        $tfoot[1][2] += $e['Count5'] + $e['Count0'];
        $tfoot[1][3] += $e['Size5'] + $e['Size0'];
        $tfoot[1][4] += $e['Count10'] + $e['Count0'] + $e['Count5'];
        $tfoot[1][5] += $e['Size10'] + $e['Size0'] + $e['Size5'];
        $tfoot[1][6] += $e['Count15'] + $e['Count0'] + $e['Count5'] + $e['Count10'];
        $tfoot[1][7] += $e['Size15'] + $e['Size0'] + $e['Size5'] + $e['Size10'];
        $tfoot[1][8] += $e['qt'];
        $tfoot[1][9] += $e['si'];
        // состояние раздела (цвет)
        if (preg_match('/DVD|HD/', $e['na'])) {
            $size = pow(1024, 4);
            if ($e['Size5'] + $e['Size0'] < $size) {
                $state = $e['Size5'] + $e['Size0'] >= $size * 3 / 4 ? 'warning' : 'ok';
            } else {
                $state = 'critical';
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
                    $state = 'warning';
                } else {
                    $state = 'ok';
                }
            } else {
                $state = 'critical';
            }
        }
        // байты
        $e['Size0'] = convert_bytes($e['Size0']);
        $e['Size5'] = convert_bytes($e['Size5']);
        $e['Size10'] = convert_bytes($e['Size10']);
        $e['Size15'] = convert_bytes($e['Size15']);
        $e['si'] = convert_bytes($e['si']);
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

    echo json_encode(array(
        'tbody' => $tbody,
        'tfoot' => $tfoot,
    ));

} catch (Exception $e) {

    echo json_encode(array(
        'tbody' => '<tr><th colspan="12">' . $e->getMessage() . '</th></tr>',
        'tfoot' => '',
    ));

}
