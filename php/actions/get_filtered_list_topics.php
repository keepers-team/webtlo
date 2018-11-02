<?php

try {

    include_once dirname(__FILE__) . '/../common.php';

    if (isset($_POST['forum_id'])) {
        $forum_id = $_POST['forum_id'];
    }

    if (
        !isset($forum_id)
        || !is_numeric($forum_id)
    ) {
        throw new Exception("Некорректный идентификатор подраздела: $forum_id");
    }

    // получаем настройки
    $cfg = get_settings();

    // кодировка для regexp
    mb_regex_encoding('UTF-8');

    // парсим параметры фильтра
    parse_str($_POST['filter'], $filter);

    if (!isset($filter['filter_sort'])) {
        throw new Exception("Не выбрано поле для сортировки");
    }

    if (!isset($filter['filter_sort_direction'])) {
        throw new Exception("Не выбрано направление сортировки");
    }

    // 0 - из других подразделов
    // -1 - незарегистрированные
    // -2 - черный список
    // -3 - все хранимые

    // topic_data => tag,id,na,si,convert(si)rg,se,ds
    $pattern_topic_block = '<div class="topic_data"><label>%s</label> <span class="bold">%s</span></div>';
    $pattern_topic_data = array(
        'id' => '<input type="checkbox" name="topics_ids[]" class="topic" value="%2$s" data-size="%4$s" data-tag="%1$s">',
        'ds' => ' <i class="fa fa-circle %8$s"></i>',
        'rg' => ' | <span>%6$s | </span> ',
        'na' => '<a href="' . $cfg['forum_url'] . '/forum/viewtopic.php?t=%2$s" target="_blank">%3$s</a>',
        'si' => ' (%5$s)',
        'se' => ' - <span class="text-danger">%7$s</span>',
    );

    $output = '';
    $filtered_topics_count = 0;
    $filtered_topics_size = 0;

    if ($forum_id == 0) {

        // сторонние раздачи
        $topics = Db::query_database(
            "SELECT id,na,si,rg,ss,se FROM TopicsUntracked",
            array(),
            true
        );
        // сортировка раздач
        $topics = natsort_field(
            $topics,
            $filter['filter_sort'],
            $filter['filter_sort_direction']
        );
        // выводим раздачи
        foreach ($topics as $topic_id => $topic_data) {
            $data = '';
            $filtered_topics_count++;
            $filtered_topics_size += $topic_data['si'];
            foreach ($pattern_topic_data as $field => $pattern) {
                if (isset($topic_data[$field])) {
                    $data .= $pattern;
                }
            }
            $output .= sprintf(
                $pattern_topic_block,
                sprintf(
                    $data,
                    $filtered_topics_count,
                    $topic_data['id'],
                    $topic_data['na'],
                    $topic_data['si'],
                    convert_bytes($topic_data['si']),
                    date('d.m.Y', $topic_data['rg']),
                    $topic_data['se']
                ),
                '#' . $topic_data['ss']
            );
        }

    } elseif ($forum_id == -2) {

        // находим значение за последний день
        $se = $cfg['avg_seeders'] ? '(se * 1.) / qt as se' : 'se';
        // чёрный список
        $topics = Db::query_database(
            "SELECT Topics.id,na,si,rg,$se,comment FROM Topics
			LEFT JOIN Blacklist ON Topics.id = Blacklist.id
			WHERE Blacklist.id IS NOT NULL",
            array(),
            true
        );
        // сортировка раздач
        $topics = natsort_field(
            $topics,
            $filter['filter_sort'],
            $filter['filter_sort_direction']
        );
        // выводим раздачи
        foreach ($topics as $topic_id => $topic_data) {
            $data = '';
            $filtered_topics_count++;
            $filtered_topics_size += $topic_data['si'];
            foreach ($pattern_topic_data as $field => $pattern) {
                if (isset($topic_data[$field])) {
                    $data .= $pattern;
                }
            }
            $output .= sprintf(
                $pattern_topic_block,
                sprintf(
                    $data,
                    $filtered_topics_count,
                    $topic_data['id'],
                    $topic_data['na'],
                    $topic_data['si'],
                    convert_bytes($topic_data['si']),
                    date('d.m.Y', $topic_data['rg']),
                    round($topic_data['se'])
                ),
                $topic_data['comment']
            );
        }

    } elseif ($forum_id == -3 || $forum_id > 0) {

        // все хранимые раздачи

        // не выбраны статусы раздач
        if (empty($filter['filter_tracker_status'])) {
            throw new Exception("Не выбраны статусы раздач для трекера");
        }

        if (empty($filter['filter_client_status'])) {
            throw new Exception("Не выбраны статусы раздач для торрент-клиента");
        }

        // некорретный ввод значения сидов
        if (isset($filter['filter_interval'])) {
            if (
                !is_numeric($filter['filter_rule_interval']['from'])
                || !is_numeric($filter['filter_rule_interval']['to'])
            ) {
                throw new Exception("В фильтре введено некорректное значение сидов");
            }
            if (
                $filter['filter_rule_interval']['from'] < 0
                || $filter['filter_rule_interval']['to'] < 0
            ) {
                throw new Exception("Значение сидов в фильтре должно быть больше 0");
            }
            if ($filter['filter_rule_interval']['from'] > $filter['filter_rule_interval']['to']) {
                throw new Exception("Начальное значение сидов в фильтре должно быть меньше или равно конечному значению");
            }
        } else {
            if (!is_numeric($filter['filter_rule'])) {
                throw new Exception("В фильтре введено некорректное значение сидов");
            }

            if ($filter['filter_rule'] < 0) {
                throw new Exception("Значение сидов в фильтре должно быть больше 0");
            }
        }

        // некорректная дата
        $date_release = DateTime::createFromFormat('d.m.Y', $filter['filter_date_release']);
        if (!$date_release) {
            throw new Exception("В фильтре введена некорректная дата создания релиза");
        }

        // хранимые подразделы
        if ($forum_id > 0) {
            $forums_ids = array($forum_id);
        } else {
            foreach ($cfg['subsections'] as $forum_id => $subsection) {
                if (!$subsection['hide_topics']) {
                    $forums_ids[] = $forum_id;
                }
            }
        }

        $ss = str_repeat('?,', count($forums_ids) - 1) . '?';
        $st = str_repeat('?,', count($filter['filter_tracker_status']) - 1) . '?';
        $dl = 'abs(dl) IS ' . implode(' OR abs(dl) IS ', $filter['filter_client_status']);

        // 1 - fields, 2 - left join, 3 - where
        $pattern_statement = "SELECT Topics.id,na,si,rg%s FROM Topics
			LEFT JOIN Clients ON Topics.hs = Clients.hs%s
			LEFT JOIN (SELECT * FROM Keepers GROUP BY id) Keepers ON Topics.id = Keepers.id
			LEFT JOIN (SELECT * FROM Blacklist GROUP BY id) Blacklist ON Topics.id = Blacklist.id
			WHERE ss IN ($ss) AND st IN ($st) AND ($dl) AND Blacklist.id IS NULL%s";

        $fields = array();
        $where = array();
        $left_join = array();

        if ($cfg['avg_seeders']) {
            // некорректный период средних сидов
            if (!is_numeric($filter['avg_seeders_period'])) {
                throw new Exception("В фильтре введено некорректное значение для периода средних сидов");
            }
            // жёсткое ограничение на 30 дней для средних сидов
            $filter['avg_seeders_period'] = $filter['avg_seeders_period'] > 0 ? $filter['avg_seeders_period'] : 1;
            $filter['avg_seeders_period'] = $filter['avg_seeders_period'] <= 30 ? $filter['avg_seeders_period'] : 30;
            for ($i = 0; $i < $filter['avg_seeders_period']; $i++) {
                $avg['sum_se'][] = "CASE WHEN d$i IS \"\" OR d$i IS NULL THEN 0 ELSE d$i END";
                $avg['sum_qt'][] = "CASE WHEN q$i IS \"\" OR q$i IS NULL THEN 0 ELSE q$i END";
                $avg['qt'][] = "CASE WHEN q$i IS \"\" OR q$i IS NULL THEN 0 ELSE 1 END";
            }
            $qt = implode('+', $avg['qt']);
            $sum_qt = implode('+', $avg['sum_qt']);
            $sum_se = implode('+', $avg['sum_se']);
            $avg = "CASE WHEN $qt IS 0 THEN (se * 1.) / qt ELSE ( se * 1. + $sum_se) / ( qt + $sum_qt) END";

            $fields[] = "$qt as ds";
            $fields[] = "$avg as se";
            $left_join[] = 'LEFT JOIN Seeders ON Topics.id = Seeders.id';
        } else {
            $fields[] = 'se';
        }

        // есть/нет хранители
        if (isset($filter['not_keepers'])) {
            $where[] = 'AND Keepers.id IS NULL';
        } elseif (isset($filter['is_keepers'])) {
            $where[] = 'AND Keepers.id IS NOT NULL';
        }

        // данные о других хранителях
        $keepers = Db::query_database(
            "SELECT id,nick FROM Keepers WHERE id IN (
                SELECT id FROM Topics WHERE ss IN ($ss)
            )",
            $forums_ids,
            true,
            PDO::FETCH_COLUMN | PDO::FETCH_GROUP
        );

        $statement = sprintf(
            $pattern_statement,
            ',' . implode(',', $fields),
            ' ' . implode(' ', $left_join),
            ' ' . implode(' ', $where)
        );

        // из базы
        $topics = Db::query_database(
            $statement,
            array_merge(
                $forums_ids,
                $filter['filter_tracker_status']
            ),
            true
        );

        // сортировка раздач
        $topics = natsort_field(
            $topics,
            $filter['filter_sort'],
            $filter['filter_sort_direction']
        );

        // выводим раздачи
        foreach ($topics as $topic_id => $topic_data) {
            // фильтрация по дате релиза
            if ($topic_data['rg'] > $date_release->format('U')) {
                continue;
            }
            // фильтрация по количеству сидов
            if (isset($filter['filter_interval'])) {
                if (
                    $filter['filter_rule_interval']['from'] > $topic_data['se']
                    || $filter['filter_rule_interval']['to'] < $topic_data['se']
                ) {
                    continue;
                }
            } else {
                if ($filter['filter_rule_direction']) {
                    if ($filter['filter_rule'] < $topic_data['se']) {
                        continue;
                    }
                } else {
                    if ($filter['filter_rule'] > $topic_data['se']) {
                        continue;
                    }
                }
            }
            // фильтрация по статусу "зелёные"
            if (
                isset($topic_data['ds'])
                && isset($filter['avg_seeders_complete'])
                && $filter['avg_seeders_period'] > $topic_data['ds']
            ) {
                continue;
            }
            // список хранителей на раздаче
            $keepers_list = '';
            if (isset($keepers[$topic_data['id']])) {
                $keepers_list = array_map(function ($e) {
                    return '<span class="keeper">' . $e . '</span>';
                }, $keepers[$topic_data['id']]);
                $keepers_list = '~> ' . implode(', ', $keepers_list);
            }
            // фильтрация по фразе
            if (!empty($filter['filter_phrase'])) {
                if (empty($filter['filter_by_phrase'])) {
                    if (!mb_eregi($filter['filter_phrase'], $keepers_list)) {
                        continue;
                    }
                } else {
                    if (!mb_eregi($filter['filter_phrase'], $topic_data['na'])) {
                        continue;
                    }
                }
            }
            $data = '';
            $filtered_topics_count++;
            $filtered_topics_size += $topic_data['si'];
            foreach ($pattern_topic_data as $field => $pattern) {
                if (isset($topic_data[$field])) {
                    $data .= $pattern;
                }
            }
            // цвет пульки
            $bullet = '';
            if (isset($topic_data['ds'])) {
                if ($topic_data['ds'] < $filter['avg_seeders_period']) {
                    $bullet = $topic_data['ds'] >= $filter['avg_seeders_period'] / 2 ? 'text-warning' : 'text-danger';
                } else {
                    $bullet = 'text-success';
                }
            }
            $output .= sprintf(
                $pattern_topic_block,
                sprintf(
                    $data,
                    $filtered_topics_count,
                    $topic_data['id'],
                    $topic_data['na'],
                    $topic_data['si'],
                    convert_bytes($topic_data['si']),
                    date('d.m.Y', $topic_data['rg']),
                    round($topic_data['se'], 2),
                    $bullet
                ),
                $keepers_list
            );
        }

    }

    echo json_encode(array(
        'log' => '',
        'topics' => $output,
        'size' => $filtered_topics_size,
        'count' => $filtered_topics_count,
    ));

} catch (Exception $e) {

    echo json_encode(array(
        'log' => $e->getMessage(),
        'topics' => null,
        'size' => 0,
        'count' => 0,
    ));

}
