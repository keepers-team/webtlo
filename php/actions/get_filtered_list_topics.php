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
        throw new Exception('Некорректный идентификатор подраздела: ' . $forum_id);
    }

    // получаем настройки
    $cfg = get_settings();

    // кодировка для regexp
    mb_regex_encoding('UTF-8');

    // парсим параметры фильтра
    parse_str($_POST['filter'], $filter);

    if (!isset($filter['filter_sort'])) {
        throw new Exception('Не выбрано поле для сортировки');
    }

    if (!isset($filter['filter_sort_direction'])) {
        throw new Exception('Не выбрано направление сортировки');
    }

    // 0 - из других подразделов
    // -1 - незарегистрированные
    // -2 - черный список
    // -3 - все хранимые
    // -4 - дублирующиеся раздачи
    // -5 - высокоприоритетные раздачи
    // -6 - раздачи своим по спискам

    // topic_data => id,na,si,convert(si)rg,se,ds,cl
    $pattern_topic_block = '<div class="topic_data"><label>%s</label> %s</div>';
    $pattern_topic_data = [
        'id' => '<input type="checkbox" name="topic_hashes[]" class="topic" value="%1$s" data-size="%4$s">',
        'ds' => ' <i class="fa %9$s %8$s" title="%11$s"></i>',
        'rg' => ' | <span>%6$s | </span> ',
        'cl' => ' <span>%10$s | </span> ',
        'na' => '<a href="' . $cfg['forum_address'] . '/forum/viewtopic.php?t=%2$s" target="_blank">%3$s</a>',
        'si' => ' (%5$s)',
        'se' => ' - <span class="text-danger">%7$s</span>',
    ];

    $output = '';
    $preparedOutput = [];
    $filtered_topics_count = 0;
    $filtered_topics_size = 0;
    $excluded_topics = ["ex_count" => 0, "ex_size" => 0];

    if ($forum_id == 0) {
        // сторонние раздачи
        $topics = Db::query_database(
            'SELECT
                TopicsUntracked.id,
                TopicsUntracked.hs,
                TopicsUntracked.na,
                TopicsUntracked.si,
                TopicsUntracked.rg,
                TopicsUntracked.ss,
                TopicsUntracked.se,
                Torrents.client_id as cl
            FROM TopicsUntracked
            LEFT JOIN Torrents ON Torrents.info_hash = TopicsUntracked.hs
            WHERE TopicsUntracked.hs IS NOT NULL',
            [],
            true
        );
        $forumsTitles = Db::query_database(
            "SELECT
                id,
                na
            FROM Forums
            WHERE id IN (SELECT DISTINCT ss FROM TopicsUntracked)",
            [],
            true,
            PDO::FETCH_KEY_PAIR
        );
        // сортировка раздач
        $topics = natsort_field(
            $topics,
            $filter['filter_sort'],
            $filter['filter_sort_direction']
        );

        $pattern_topic_head =
            '<div class="subsection-title">'.
            '<a href="#" onclick="addUnsavedSubsection(%1$s, \'%2$s\');" '.
            'title="Нажмите, чтобы добавить подраздел в хранимые">(%1$s)</a> %2$s'.
            '</div>';

        // выводим раздачи
        foreach ($topics as $topic_id => $topic_data) {
            $data = '';
            $forumID = $topic_data['ss'];
            $filtered_topics_count++;
            $filtered_topics_size += $topic_data['si'];
            foreach ($pattern_topic_data as $field => $pattern) {
                if (isset($topic_data[$field])) {
                    $data .= $pattern;
                }
            }
            if (!isset($preparedOutput[$forumID])) {
                $preparedOutput[$forumID] = sprintf(
                    $pattern_topic_head,
                    $forumID,
                    $forumsTitles[$forumID]
                );
            }
            $preparedOutput[$forumID] .= sprintf(
                $pattern_topic_block,
                sprintf(
                    $data,
                    $topic_data['hs'],
                    $topic_data['id'],
                    $topic_data['na'],
                    $topic_data['si'],
                    convert_bytes($topic_data['si']),
                    date('d.m.Y', $topic_data['rg']),
                    $topic_data['se'],
                    '',
                    '',
                    get_client_name($topic_data['cl'], $cfg)
                ),
                ''
            );
        }
        unset($topics);
        natcasesort($preparedOutput);
        $output = implode('', $preparedOutput);
    } elseif ($forum_id == -1) {
        // незарегистрированные раздачи
        $topics = Db::query_database(
            'SELECT
                Torrents.topic_id,
                CASE WHEN TopicsUnregistered.name IS "" OR TopicsUnregistered.name IS NULL THEN Torrents.name ELSE TopicsUnregistered.name END as name,
                TopicsUnregistered.status,
                Torrents.info_hash,
                Torrents.client_id,
                Torrents.total_size,
                Torrents.time_added,
                Torrents.paused,
                Torrents.done
            FROM TopicsUnregistered
            LEFT JOIN Torrents ON TopicsUnregistered.info_hash = Torrents.info_hash
            WHERE TopicsUnregistered.info_hash IS NOT NULL
            ORDER BY TopicsUnregistered.name',
            [],
            true
        );
        // формирование строки вывода
        foreach ($topics as $topic) {
            $filtered_topics_count++;
            $filtered_topics_size += $topic['total_size'];
            $topicStatus = $topic['status'];

            $topicBlock = '';
            foreach ($pattern_topic_data as $field => $pattern) {
                if (in_array($field, ['id', 'rg', 'ds', 'cl', 'na', 'si'])) {
                    $topicBlock .= $pattern;
                }
            }
            // тип пульки: раздаю, качаю, на паузе, ошибка
            $stateTorrentClient = '';
            if ($topic['done'] == 1) {
                $stateTorrentClient = 'fa-arrow-up';
            } elseif ($topic['done'] === null) {
                $stateTorrentClient = 'fa-circle';
            } else {
                $stateTorrentClient = 'fa-arrow-down';
            }
            if ($topic['paused'] == 1) {
                $stateTorrentClient = 'fa-pause';
            }
            if (!isset($preparedOutput[$topicStatus])) {
                $preparedOutput[$topicStatus] = '<div class="subsection-title">' . $topicStatus . '</div>';
            }
            $preparedOutput[$topicStatus] .= sprintf(
                $pattern_topic_block,
                sprintf(
                    $topicBlock,
                    $topic['info_hash'],
                    $topic['topic_id'],
                    $topic['name'],
                    $topic['total_size'],
                    convert_bytes($topic['total_size']),
                    date('d.m.Y', $topic['time_added']),
                    '',
                    'text-success',
                    $stateTorrentClient,
                    get_client_name($topic['client_id'], $cfg),
                    get_topic_title($stateTorrentClient)
                ),
                ''
            );
        }
        unset($topics);
        natcasesort($preparedOutput);
        $output = implode('', $preparedOutput);
    } elseif ($forum_id == -2) {
        // находим значение за последний день
        $se = $cfg['avg_seeders'] ? '(se * 1.) / qt as se' : 'se';
        // чёрный список
        $topics = Db::query_database(
            'SELECT
                Topics.id,
                Topics.hs,
                Topics.ss,
                Topics.na,
                Topics.si,
                Topics.rg,'
                . $se . ',
                TopicsExcluded.comment
            FROM Topics
            LEFT JOIN TopicsExcluded ON Topics.hs = TopicsExcluded.info_hash
            WHERE TopicsExcluded.info_hash IS NOT NULL',
            [],
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
            $forumID = $topic_data['ss'];
            $filtered_topics_count++;
            $filtered_topics_size += $topic_data['si'];
            foreach ($pattern_topic_data as $field => $pattern) {
                if (isset($topic_data[$field])) {
                    $data .= $pattern;
                }
            }
            if (!isset($preparedOutput[$forumID])) {
                $preparedOutput[$forumID] = '<div class="subsection-title">' . $cfg['subsections'][$forumID]['na'] . '</div>';
            }
            $preparedOutput[$forumID] .= sprintf(
                $pattern_topic_block,
                sprintf(
                    $data,
                    $topic_data['hs'],
                    $topic_data['id'],
                    $topic_data['na'],
                    $topic_data['si'],
                    convert_bytes($topic_data['si']),
                    date('d.m.Y', $topic_data['rg']),
                    round($topic_data['se'])
                ),
                '<span class="bold">' . $topic_data['comment'] . '</span>'
            );
        }
        unset($topics);
        natcasesort($preparedOutput);
        $output = implode('', $preparedOutput);
    } elseif ($forum_id == -4) {
        // дублирующиеся раздачи
        $statementFields = [];
        $statementLeftJoin = [];
        if ($cfg['avg_seeders']) {
            if (!is_numeric($filter['avg_seeders_period'])) {
                throw new Exception('В фильтре введено некорректное значение для периода средних сидов');
            }
            $filter['avg_seeders_period'] = $filter['avg_seeders_period'] > 0 ? $filter['avg_seeders_period'] : 1;
            $filter['avg_seeders_period'] = $filter['avg_seeders_period'] <= 30 ? $filter['avg_seeders_period'] : 30;
            for ($dayNumber = 0; $dayNumber < $filter['avg_seeders_period']; $dayNumber++) {
                $statementTotal['seeders'][] = 'CASE WHEN d' . $dayNumber . ' IS "" OR d' . $dayNumber . ' IS NULL THEN 0 ELSE d' . $dayNumber . ' END';
                $statementTotal['updates'][] = 'CASE WHEN q' . $dayNumber . ' IS "" OR q' . $dayNumber . ' IS NULL THEN 0 ELSE q' . $dayNumber . ' END';
                $statementTotal['values'][] = 'CASE WHEN q' . $dayNumber . ' IS "" OR q' . $dayNumber . ' IS NULL THEN 0 ELSE 1 END';
            }
            $statementTotalValues = implode('+', $statementTotal['values']);
            $statementTotalUpdates = implode('+', $statementTotal['updates']);
            $statementTotalSeeders = implode('+', $statementTotal['seeders']);
            $statementAverageSeeders = 'CASE WHEN ' . $statementTotalValues . ' IS 0 THEN (se * 1.) / qt ELSE ( se * 1. + ' . $statementTotalSeeders . ') / ( qt + ' . $statementTotalUpdates . ') END';
            $statementFields = [
                $statementTotalValues . ' as ds',
                $statementAverageSeeders . ' as se'
            ];
            $statementLeftJoin[] = 'LEFT JOIN Seeders ON Topics.id = Seeders.id';
        } else {
            $statementFields[] = 'se';
        }
        $statementSQL =
            'SELECT
                Topics.id,
                Topics.hs,
                Topics.na,
                Topics.si,
                Topics.rg
                %s
            FROM Topics %s
            WHERE Topics.hs IN (SELECT info_hash FROM Torrents GROUP BY info_hash HAVING count(*) > 1)';
        $statement = sprintf(
            $statementSQL,
            ',' . implode(',', $statementFields),
            ' ' . implode(' ', $statementLeftJoin)
        );
        $topicsData = Db::query_database($statement, [], true);
        $topicsData = natsort_field(
            $topicsData,
            $filter['filter_sort'],
            $filter['filter_sort_direction']
        );
        foreach ($topicsData as $topicID => $topicData) {
            $outputLine = '';
            $filtered_topics_count++;
            $filtered_topics_size += $topicData['si'];
            foreach ($pattern_topic_data as $field => $pattern) {
                if (isset($topicData[$field])) {
                    $outputLine .= $pattern;
                }
            }
            $stateAverageSeeders = '';
            if (isset($topicData['ds'])) {
                if ($topicData['ds'] < $filter['avg_seeders_period']) {
                    $stateAverageSeeders = $topicData['ds'] >= $filter['avg_seeders_period'] / 2 ? 'text-warning' : 'text-danger';
                } else {
                    $stateAverageSeeders = 'text-success';
                }
            }
            $statement =
                'SELECT
                    client_id,
                    done,
                    paused,
                    error
                FROM Torrents
                WHERE info_hash = ?
                ORDER BY client_id';
            $listTorrentClientsIDs = Db::query_database(
                $statement,
                [$topicData['hs']],
                true
            );
            // сортировка торрент-клиентов
            $sortOrderTorrentClients = array_flip(array_keys($cfg['clients']));
            usort($listTorrentClientsIDs, function ($a, $b) use ($sortOrderTorrentClients) {
                return $sortOrderTorrentClients[$a['client_id']] - $sortOrderTorrentClients[$b['client_id']];
            });
            $formatTorrentClientList = '<i class="fa fa-%1$s text-%2$s"></i> <i class="bold text-%2$s">%3$s</i>';
            $listTorrentClientsNames = array_map(function ($e) use ($cfg, $formatTorrentClientList) {
                if (isset($cfg['clients'][$e['client_id']])) {
                    if ($e['done'] == 1) {
                        $stateTorrentClientStatus = 'arrow-up';
                        $stateTorrentClientColor = 'success';
                    } else {
                        $stateTorrentClientStatus = 'arrow-down';
                        $stateTorrentClientColor = 'danger';
                    }
                    if ($e['paused'] == 1) {
                        $stateTorrentClientStatus = 'pause';
                    }
                    if ($e['error'] == 1) {
                        $stateTorrentClientStatus = 'times';
                        $stateTorrentClientColor = 'danger';
                    }
                    return sprintf(
                        $formatTorrentClientList,
                        $stateTorrentClientStatus,
                        $stateTorrentClientColor,
                        $cfg['clients'][$e['client_id']]['cm']
                    );
                }
            }, $listTorrentClientsIDs);
            $listTorrentClientsNames = '| ' . implode(', ', $listTorrentClientsNames);
            $output .= sprintf(
                $pattern_topic_block,
                sprintf(
                    $outputLine,
                    $topicData['hs'],
                    $topicData['id'],
                    $topicData['na'],
                    $topicData['si'],
                    convert_bytes($topicData['si']),
                    date('d.m.Y', $topicData['rg']),
                    round($topicData['se']),
                    $stateAverageSeeders,
                    'fa-circle',
                    '',
                    ''
                ),
                $listTorrentClientsNames
            );
        }
    } elseif (
        $forum_id > 0       // заданный раздел
        || $forum_id == -3  // все хранимые подразделы
        || $forum_id == -5  // высокий приоритет
        || $forum_id == -6  // все хранимые подразделы по спискам
    ) {
        // все хранимые раздачи
        // не выбраны статусы раздач
        if (empty($filter['filter_tracker_status'])) {
            throw new Exception('Не выбраны статусы раздач для трекера');
        }

        if (empty($filter['keeping_priority'])) {
            if ($forum_id == -5) {
                $filter['keeping_priority'] = [2];
            } else {
                throw new Exception('Не выбраны приоритеты раздач для трекера');
            }
        }

        if (empty($filter['filter_client_status'])) {
            throw new Exception('Не выбраны статусы раздач для торрент-клиента');
        }

        // некорретный ввод значения сидов или количества хранителей
        $filters_hints = [
            "filter_rule_interval" => "сидов",
            "keepers_filter_rule_interval" => "количества хранителей",
        ];
        foreach ($filters_hints as $filter_name => $hint) {
            if (isset($filter['filter_interval']) || $filter_name == "keepers_filter_rule_interval") {
                if (
                    !is_numeric($filter[$filter_name]['from'])
                    || !is_numeric($filter[$filter_name]['to'])
                ) {
                    throw new Exception('В фильтре введено некорректное значение ' . $hint);
                }
                if ($filter[$filter_name]['from'] < 0 || $filter[$filter_name]['to'] < 0) {
                    throw new Exception('Значение ' . $hint . ' в фильтре должно быть больше 0');
                }
                if ($filter[$filter_name]['from'] > $filter[$filter_name]['to']) {
                    throw new Exception('Начальное значение ' . $hint . ' в фильтре должно быть меньше или равно конечному значению');
                }
            } else {
                if (!is_numeric($filter['filter_rule'])) {
                    throw new Exception('В фильтре введено некорректное значение ' . $hint);
                }

                if ($filter['filter_rule'] < 0) {
                    throw new Exception('Значение ' . $hint . ' в фильтре должно быть больше 0');
                }
            }
        }

        // некорректная дата
        $date_release = DateTime::createFromFormat('d.m.Y', $filter['filter_date_release']);
        if (!$date_release) {
            throw new Exception('В фильтре введена некорректная дата создания релиза');
        }

        // Исключить себя из списка хранителей.
        $exclude_self_keep = $cfg['exclude_self_keep'];

        // хранимые подразделы
        if ($forum_id > 0) {
            $forumsIDs = [$forum_id];
        } elseif ($forum_id == -5) {
            $forumsIDs = Db::query_database(
                'SELECT DISTINCT ss FROM Topics WHERE pt = 2',
                [],
                true,
                PDO::FETCH_COLUMN
            );
            if (empty($forumsIDs)) {
                $forumsIDs = [0];
            }
        } else {
            // -3 || -6
            if (isset($cfg['subsections'])) {
                foreach ($cfg['subsections'] as $sub_forum_id => $subsection) {
                    if (!$subsection['hide_topics']) {
                        $forumsIDs[] = $sub_forum_id;
                    }
                }
            } else {
                $forumsIDs = [0];
            }
        }

        // Шаблоны для подразделов, статусов раздач, приоритета хранения.
        $ss = str_repeat('?,', count($forumsIDs) - 1) . '?';
        $st = str_repeat('?,', count($filter['filter_tracker_status']) - 1) . '?';
        $pt = str_repeat('?,', count($filter['keeping_priority']) - 1) . '?';
        // Шаблон для статуса хранения.
        $torrentDone = 'CAST(done as INT) IS ' . implode(' OR CAST(done AS INT) IS ', $filter['filter_client_status']);


        // Данный подзапрос, для каждой раздачи, определяет наличие:
        // - хотя бы одного хранителя, у которого раздача есть в списке, "хранитель", Keepers, "posted"
        // - хотя бы одного хранителя, который в данный момент раздаёт эту раздачу, "сид-хранитель", KeepersSeeders, "seeding"
        $keepers_status_statement = sprintf(
            'SELECT
                id,
                MAX(posted) as posted,
                complete,
                MAX(seeding) as seeding
            FROM (
                SELECT
                    tp.id,
                    k.complete,
                    k.posted,
                    NULL as seeding
                FROM Topics as tp
                INNER JOIN Keepers k ON k.id = tp.id
                %1$s
                UNION ALL
                SELECT
                    tp.id,
                    1 as complete,
                    NULL as posted,
                    1 as seeding
                FROM Topics as tp
                INNER JOIN KeepersSeeders as k ON k.topic_id = tp.id
                %1$s
            )
            GROUP BY id',
            // Исключаем себя из списка, при необходимости
            $exclude_self_keep ? "WHERE k.nick != '{$cfg['tracker_login']}' COLLATE NOCASE" : ''
        );

        // 1 - fields, 2 - left join, 3 - keepers check, 4 - where
        $pattern_statement =
            'SELECT
                Topics.id,
                Topics.hs,
                Topics.na,
                Topics.si,
                Topics.rg,
                Topics.pt,
                Torrents.done,
                Torrents.paused,
                Torrents.error,
                Torrents.client_id as cl
                %s
            FROM Topics
            LEFT JOIN Torrents ON Topics.hs = Torrents.info_hash
            %s
            LEFT JOIN (
                %s
            ) Keepers ON Topics.id = Keepers.id
            LEFT JOIN (SELECT info_hash FROM TopicsExcluded GROUP BY info_hash) TopicsExcluded ON Topics.hs = TopicsExcluded.info_hash
            WHERE
                ss IN (' . $ss . ')
                AND st IN (' . $st . ')
                AND pt IN (' . $pt . ')
                AND (' . $torrentDone . ')
                AND TopicsExcluded.info_hash IS NULL
                %s';

        $fields = [];
        $where = [];
        $left_join = [];

        if ($cfg['avg_seeders']) {
            // некорректный период средних сидов
            if (!is_numeric($filter['avg_seeders_period'])) {
                throw new Exception('В фильтре введено некорректное значение для периода средних сидов');
            }
            // жёсткое ограничение на 30 дней для средних сидов
            $filter['avg_seeders_period'] = $filter['avg_seeders_period'] > 0 ? $filter['avg_seeders_period'] : 1;
            $filter['avg_seeders_period'] = $filter['avg_seeders_period'] <= 30 ? $filter['avg_seeders_period'] : 30;
            for ($i = 0; $i < $filter['avg_seeders_period']; $i++) {
                $avg['sum_se'][] = 'CASE WHEN d' . $i . ' IS "" OR d' . $i . ' IS NULL THEN 0 ELSE d' . $i . ' END';
                $avg['sum_qt'][] = 'CASE WHEN q' . $i . ' IS "" OR q' . $i . ' IS NULL THEN 0 ELSE q' . $i . ' END';
                $avg['qt'][] = 'CASE WHEN q' . $i . ' IS "" OR q' . $i . ' IS NULL THEN 0 ELSE 1 END';
            }
            $qt = implode('+', $avg['qt']);
            $sum_qt = implode('+', $avg['sum_qt']);
            $sum_se = implode('+', $avg['sum_se']);
            $avg = 'CASE WHEN ' . $qt . ' IS 0 THEN (se * 1.) / qt ELSE ( se * 1. + ' . $sum_se . ') / ( qt + ' . $sum_qt . ') END';

            $fields[] = $qt . ' as ds';
            $fields[] = $avg . ' as se';
            $left_join[] = 'LEFT JOIN Seeders ON Topics.id = Seeders.id';
        } else {
            $fields[] = 'se';
        }

        // есть/нет хранители
        if (isset($filter['not_keepers'])) {
            $where[] = 'AND Keepers.posted IS NULL AND (posted IS NULL OR rg > posted)';
        } elseif (isset($filter['is_keepers'])) {
            $where[] = 'AND Keepers.posted IS NOT NULL AND (posted IS NULL OR rg < posted)';
        }

        // есть/нет сиды-хранители
        if (isset($filter['not_keepers_seeders'])) {
            $where[] = 'AND seeding IS NULL';
        } elseif (isset($filter['is_keepers_seeders'])) {
            $where[] = 'AND seeding = 1';
        }

        // данные о других хранителях
        $forumsIDsChunks = array_chunk($forumsIDs, 499);
        $keepers = [];
        foreach ($forumsIDsChunks as $forumsIDsChunk) {
            $keepers += Db::query_database(
                'SELECT k.id,k.nick,MAX(k.complete) as complete,MAX(k.posted) as posted,MAX(k.seeding) as seeding FROM (
                    SELECT Topics.id,Keepers.nick,complete,posted,NULL as seeding FROM Topics
                    LEFT JOIN Keepers ON Topics.id = Keepers.id
                    WHERE ss IN (' . $ss . ') AND rg < posted AND Keepers.id IS NOT NULL
                    UNION ALL
                    SELECT topic_id,nick,1 as complete,NULL as posted,1 as seeding FROM Topics
                    LEFT JOIN KeepersSeeders ON Topics.id = KeepersSeeders.topic_id
                    WHERE ss IN (' . $ss . ') AND KeepersSeeders.topic_id IS NOT NULL
                ) as k
                GROUP BY id, nick
                ORDER BY (CASE WHEN k.nick == ? COLLATE NOCASE THEN 1 ELSE 0 END) DESC',
                array_merge($forumsIDsChunk, $forumsIDsChunk, [$cfg['tracker_login']]),
                true,
                PDO::FETCH_ASSOC | PDO::FETCH_GROUP
            );
        }

        // 1 - fields, 2 - left join, 3 - keepers check, 4 - where
        $statement = sprintf(
            $pattern_statement,
            ',' . implode(',', $fields),
            ' ' . implode(' ', $left_join),
            $keepers_status_statement,
            ' ' . implode(' ', $where)
        );

        // из базы
        $topics = Db::query_database(
            $statement,
            array_merge(
                $forumsIDs,
                $filter['filter_tracker_status'],
                $filter['keeping_priority'],
            ),
            true
        );

        // сортировка раздач
        $topics = natsort_field(
            $topics,
            $filter['filter_sort'],
            $filter['filter_sort_direction']
        );

        // фильтрация по фразе е=ё
        if (!empty($filter['filter_phrase'])) {
            $filterByTopicName = preg_replace(
                '/[её]/ui',
                '(е|ё)',
                quotemeta($filter['filter_phrase'])
            );

            // Удалим лишние пробелы из набора
            $filterValues = explode(',', preg_replace('/\s+/', '', $filter['filter_phrase']));
            $filterValues = array_filter($filterValues);
        }

        // выводим раздачи
        foreach ($topics as $topic_id => $topic_data) {
            // фильтрация по клиенту
            if ($filter['filter_client_id'] > 0 && $filter['filter_client_id'] != $topic_data['cl']) {
                continue;
            }
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
            $topic_keepers = [];
            if (isset($keepers[$topic_data['id']])) {
                $topic_keepers = $keepers[$topic_data['id']];
            }
            // фильтрация раздач по своим спискам
            if ($forum_id == -6) {
                $exclude_self_keep = 0;
                $topicKeepers = array_map('strtolower', array_column($topic_keepers, 'nick'));
                if (!count($topicKeepers) || !in_array(strtolower($cfg['tracker_login']), $topicKeepers)) {
                    continue;
                }
            }
            // исключим себя из списка хранителей раздачи
            if ($exclude_self_keep) {
                $topic_keepers =  array_filter($topic_keepers, function($e) use ($cfg) {
                    return strcasecmp($cfg['tracker_login'], $e['nick']) !== 0;
                });
            }
            $keepers_list = '';
            if (count($topic_keepers)) {
                $formatKeeperList = '<i class="fa fa-%1$s text-%2$s" title="%4$s"></i> <i class="keeper bold text-%2$s" title="%4$s">%3$s</i>';
                $keepers_list = array_map(function ($e) use ($formatKeeperList, $cfg) {
                    if ($e['complete'] == 1) {
                        if ($e['posted'] === null) {
                            $stateKeeperIcon = 'arrow-circle-up';
                        } else {
                            $stateKeeperIcon = $e['seeding'] == 1 ? 'upload' : 'arrow-up';
                        }
                        $stateKeeperColor = 'success';
                    } else {
                        $stateKeeperIcon = 'arrow-down';
                        $stateKeeperColor = 'danger';
                    }
                    if (strcasecmp($cfg['tracker_login'], $e['nick']) === 0) {
                        $stateKeeperColor = 'self';
                    }
                    return sprintf(
                        $formatKeeperList,
                        $stateKeeperIcon,
                        $stateKeeperColor,
                        $e['nick'],
                        get_keeper_title($stateKeeperIcon)
                    );
                }, $topic_keepers);
                $keepers_list = '| ' . implode(', ', $keepers_list);
            }
            // фильтрация по фразе
            if (!empty($filter['filter_phrase'])) {
                if ($filter['filter_by_phrase'] == 0) { // в имени хранителя
                    $topicKeepers = array_column($topic_keepers, 'nick');
                    unset($matchKeepers);
                    foreach ($filterValues as $filterKeeper) {
                        if (empty($filterKeeper)) {
                            continue;
                        }
                        if (mb_substr($filterKeeper, 0, 1) === '!') {
                            $matchKeepers[] = !in_array(mb_substr($filterKeeper, 1), $topicKeepers);
                        } else {
                            $matchKeepers[] = in_array($filterKeeper, $topicKeepers);
                        }
                    }
                    if (in_array(0, $matchKeepers)) {
                        continue;
                    }
                } elseif ($filter['filter_by_phrase'] == 1) { // в названии раздачи
                    if (!mb_eregi($filterByTopicName, $topic_data['na'])) {
                        continue;
                    }
                } elseif ($filter['filter_by_phrase'] == 2) { // в номере/ид раздачи
                    $matchId = false;
                    foreach ($filterValues as $filterId) {
                        $filterId = sprintf("^%s$", str_replace('*', '.*', $filterId));
                        if (mb_eregi($filterId, $topic_data['id'])) {
                            $matchId = true;
                        }
                    }
                    if (!$matchId) {
                        continue;
                    }
                }
            }

            if (
                isset($filter['is_keepers'])
                && (
                    $filter['keepers_filter_rule_interval']['from'] > count($topic_keepers)
                    || $filter['keepers_filter_rule_interval']['to'] < count($topic_keepers)
                )
            ) {
                continue;
            }
            $data = '';
            $filtered_topics_count++;
            $filtered_topics_size += $topic_data['si'];
            foreach ($pattern_topic_data as $field => $pattern) {
                if (isset($topic_data[$field])) {
                    $data .= $pattern;
                }
            }
            // тип пульки: раздаю, качаю, на паузе, ошибка
            $stateTorrentClient = '';
            if ($topic_data['done'] == 1) {
                $stateTorrentClient = 'fa-arrow-up';
            } elseif ($topic_data['done'] === null) {
                $stateTorrentClient = 'fa-circle';
            } else {
                $stateTorrentClient = 'fa-arrow-down';
            }
            if ($topic_data['paused'] == 1) {
                $stateTorrentClient = 'fa-pause';
            }
            if ($topic_data['error'] == 1) {
                $stateTorrentClient = 'fa-times';
            }
            // цвет пульки
            $bullet_color = '';
            if (isset($topic_data['ds'])) {
                if ($topic_data['ds'] < $filter['avg_seeders_period']) {
                    $bullet_color = $topic_data['ds'] >= $filter['avg_seeders_period'] / 2 ? 'text-warning' : 'text-danger';
                } else {
                    $bullet_color = 'text-success';
                }
            }
            // выводим строку
            $output .= sprintf(
                $pattern_topic_block,
                sprintf(
                    $data,
                    $topic_data['hs'],
                    $topic_data['id'],
                    $topic_data['na'],
                    $topic_data['si'],
                    convert_bytes($topic_data['si']),
                    date('d.m.Y', $topic_data['rg']),
                    round($topic_data['se'], 2),
                    $bullet_color,
                    $stateTorrentClient,
                    get_client_name($topic_data['cl'], $cfg),
                    get_topic_title($stateTorrentClient, $bullet_color)
                ),
                $keepers_list
            );
        }

        $excluded = Db::query_database(
            'SELECT COUNT(1) AS ex_count, IFNULL(SUM(t.si),0) AS ex_size
            FROM TopicsExcluded ex
            INNER JOIN Topics t on t.hs = ex.info_hash
            WHERE t.ss IN (' . $ss . ')
                AND t.st IN (' . $st . ')
                AND t.pt IN (' . $pt . ')',
            array_merge(
                $forumsIDs,
                $filter['filter_tracker_status'],
                $filter['keeping_priority'],
            ),
            true
        );
        if (count($excluded[0])) {
            $excluded_topics = $excluded[0];
        }
    }

    echo json_encode([
        'log' => '',
        'topics' => $output,
        'size' => $filtered_topics_size,
        'count' => $filtered_topics_count,
        'ex_count' => $excluded_topics['ex_count'],
        'ex_size' => $excluded_topics['ex_size'],
    ]);
} catch (Exception $e) {
    echo json_encode([
        'log' => $e->getMessage(),
        'topics' => null,
        'size' => 0,
        'count' => 0,
        'ex_count' => 0,
        'ex_size' => 0,
    ]);
}

/**
 * Собрать имя клиента
 *
 * @param      int|null  $clientID  The client id
 * @param      array     $cfg       The configuration
 *
 * @return     string    The client name.
 */
function get_client_name(int|null $clientID, array $cfg): string
{
    if (!$clientID || !isset($cfg['clients'][$clientID])) return '';
    return sprintf(
        '<i class="client bold text-success">%s</i>',
        $cfg['clients'][$clientID]['cm']
    );
}

/**
 * Собрать заголовок для раздачи, в зависимости от её состояния
 *
 * @param      string  $bulletState  Статус раздачи
 * @param      string  $bulletColor  Цвет раздачи (средние сиды)
 *
 * @return     string  Заголовок раздачи
 */
function get_topic_title(string $bulletState, string $bulletColor = ""): string
{
    $topicsBullets = [
        "fa-arrow-up"   => "Раздаётся",
        "fa-arrow-down" => "Скачивается",
        "fa-pause"      => "Приостановлена",
        "fa-circle"     => "Нет в клиенте",
        "fa-times"      => "C ошибкой в клиенте"
    ];
    $topicsColors = [
        "text-success" => "полные данные о средних сидах",
        "text-warning" => "неполные данные о средних сидах",
        "text-danger"  => "отсутствуют данные о средних сидах"
    ];
    $bulletTitle = [];
    if (isset($topicsBullets[$bulletState])) {
        $bulletTitle[]= $topicsBullets[$bulletState];
    }
    if (isset($topicsColors[$bulletColor])) {
        $bulletTitle[]= $topicsColors[$bulletColor];
    }
    return implode(", ", $bulletTitle);
}

/**
 * Собрать заголовок для хранителя в зависимости от его связи с раздачей
 *
 * @param      string  $bulletState  Состояние раздачи
 *
 * @return     string  Заголовок
 */
function get_keeper_title(string $bulletState): string
{
    $keeperBullets = [
        'upload'          => 'Есть в списке и раздаёт',
        'arrow-up'        => 'Есть в списке, не раздаёт',
        'arrow-circle-up' => 'Нет в списке и раздаёт',
        'arrow-down'      => 'Скачивает'
    ];
    return isset($keeperBullets[$bulletState]) ? $keeperBullets[$bulletState] : "";
}
