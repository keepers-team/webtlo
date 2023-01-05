<?php

use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\Utils;
use Psr\Log\LoggerInterface;

function _getFilteredListTopics(object $json, array $cfg, DB $db, LoggerInterface $logger): array
{
    if (isset($json->forum_id)) {
        $forum_id = $json->forum_id;
    } else {
        $error = "No subforum identifier in request";
        $logger->error($error, ['json' => $json]);
        return ['success' => false, 'response' => $error];
    }

    if (!is_numeric($forum_id)) {
        $error = "Malformed subforum identifier";
        $logger->error($error, ['forum_id' => $forum_id]);
        return ['success' => false, 'response' => $error];
    }

    // кодировка для regexp
    mb_regex_encoding('UTF-8');

    // парсим параметры фильтра
    parse_str($json->filter, $filter);

    if (!isset($filter['filter_sort'])) {
        $error = "There is no field to sort";
        $logger->error($error, ['forum_id' => $forum_id]);
        return ['success' => false, 'response' => $error];
    }

    if (!isset($filter['filter_sort_direction'])) {
        $error = "There is no direction to sort";
        $logger->error($error, ['forum_id' => $forum_id]);
        return ['success' => false, 'response' => $error];
    }

    // 0 - из других подразделов
    // -1 - незарегистрированные
    // -2 - черный список
    // -3 - все хранимые
    // -4 - дублирующиеся раздачи
    // -5 - высокоприоритетные раздачи

    // topic_data => id,na,si,convert(si)rg,se,ds
    $pattern_topic_block = '<div class="topic_data"><label>%s</label> %s</div>';
    $pattern_topic_data = [
        'id' => '<input type="checkbox" name="topic_hashes[]" class="topic" value="%1$s" data-size="%4$s">',
        'ds' => ' <i class="fa %9$s %8$s"></i>',
        'rg' => ' | <span>%6$s | </span> ',
        'na' => '<a href="' . $cfg['forum_address'] . '/forum/viewtopic.php?t=%2$s" target="_blank">%3$s</a>',
        'si' => ' (%5$s)',
        'se' => ' - <span class="text-danger">%7$s</span>',
    ];

    $output = '';
    $preparedOutput = [];
    $filtered_topics_count = 0;
    $filtered_topics_size = 0;

    if ($forum_id == 0) {
        // сторонние раздачи
        $topics = $db->query_database(
            'SELECT
                TopicsUntracked.id,
                TopicsUntracked.hs,
                TopicsUntracked.na,
                TopicsUntracked.si,
                TopicsUntracked.rg,
                TopicsUntracked.ss,
                TopicsUntracked.se,
                Torrents.client_id
            FROM TopicsUntracked
            LEFT JOIN Torrents ON Torrents.info_hash = TopicsUntracked.hs
            WHERE TopicsUntracked.hs IS NOT NULL',
            [],
            true
        );
        $forumsTitles = $db->query_database(
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
        $topics = Utils::natsort_field(
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
            $torrentClientID = $topic_data['client_id'];
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
                    Utils::convert_bytes($topic_data['si']),
                    date('d.m.Y', $topic_data['rg']),
                    $topic_data['se']
                ),
                '<span class="bold">' . $cfg['clients'][$torrentClientID]['cm'] . '</span>'
            );
        }
        unset($topics);
        natcasesort($preparedOutput);
        $output = implode('', $preparedOutput);
    } elseif ($forum_id == -1) {
        // незарегистрированные раздачи
        $topics = $db->query_database(
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
            $topicBlock = '';
            $filtered_topics_count++;
            $filtered_topics_size += $topic['total_size'];
            $topicStatus = $topic['status'];
            $torrentClientID = $topic['client_id'];
            foreach ($pattern_topic_data as $field => $pattern) {
                if (in_array($field, ['id', 'rg', 'ds', 'na', 'si'])) {
                    $topicBlock .= $pattern;
                }
            }
            if ($topic['done'] == 1) {
                $stateTorrentClient = 'fa-arrow-up';
            } elseif ($topic['done'] == null) {
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
                    Utils::convert_bytes($topic['total_size']),
                    date('d.m.Y', $topic['time_added']),
                    '',
                    'text-success',
                    $stateTorrentClient
                ),
                '<span class="bold">' . $cfg['clients'][$torrentClientID]['cm'] . '</span>'
            );
        }
        unset($topics);
        natcasesort($preparedOutput);
        $output = implode('', $preparedOutput);
    } elseif ($forum_id == -2) {
        // находим значение за последний день
        $se = $cfg['avg_seeders'] ? '(se * 1.) / qt as se' : 'se';
        // чёрный список
        $topics = $db->query_database(
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
        $topics = Utils::natsort_field(
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
                    Utils::convert_bytes($topic_data['si']),
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
                $error = "Incorrect value for period to compute mean seeders";
                $logger->error($error, ['avg_seeders_period' => $filter['avg_seeders_period']]);
                return ['success' => false, 'response' => $error];
            }
            $filter['avg_seeders_period'] = $filter['avg_seeders_period'] > 0 ? $filter['avg_seeders_period'] : 1;
            $filter['avg_seeders_period'] = min($filter['avg_seeders_period'], 30);
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
        $topicsData = $db->query_database($statement, [], true);
        $topicsData = Utils::natsort_field(
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
            $listTorrentClientsIDs = $db->query_database(
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
                    Utils::convert_bytes($topicData['si']),
                    date('d.m.Y', $topicData['rg']),
                    round($topicData['se']),
                    $stateAverageSeeders,
                    'fa-circle'
                ),
                $listTorrentClientsNames
            );
        }
    } elseif (
        $forum_id == -3
        || $forum_id > 0
        || $forum_id == -5
    ) {
        // все хранимые раздачи
        // не выбраны статусы раздач
        if (empty($filter['filter_tracker_status'])) {
            $error = "No statuses to filter";
            $logger->error($error, []);
            return ['success' => false, 'response' => $error];
        }

        if (empty($filter['keeping_priority'])) {
            if ($forum_id == -5) {
                $filter['keeping_priority'] = [2];
            } else {
                $error = "No priorities to filter";
                $logger->error($error, []);
                return ['success' => false, 'response' => $error];
            }
        }

        if (empty($filter['filter_client_status'])) {
            $error = "No statuses to filter in torrent-client";
            $logger->error($error, []);
            return ['success' => false, 'response' => $error];
        }

        // некорретный ввод значения сидов или количества хранителей
        $filters_hints = [
            "filter_rule_interval",
            "keepers_filter_rule_interval",
        ];
        foreach ($filters_hints as $filter_name) {
            if (isset($filter['filter_interval']) || $filter_name == "keepers_filter_rule_interval") {
                if (
                    !is_numeric($filter[$filter_name]['from'])
                    || !is_numeric($filter[$filter_name]['to'])
                ) {
                    $error = "Malformed value for filter";
                    $logger->error($error, ['name' => $filter_name, 'value' => $filter[$filter_name]]);
                    return ['success' => false, 'response' => $error];
                }
                if ($filter[$filter_name]['from'] < 0 || $filter[$filter_name]['to'] < 0) {
                    $error = "Value should be > 0";
                    $logger->error($error, ['name' => $filter_name, 'value' => $filter[$filter_name]]);
                    return ['success' => false, 'response' => $error];
                }
                if ($filter[$filter_name]['from'] > $filter[$filter_name]['to']) {
                    $error = "Starting value for filter should be greater or equal to ending one";
                    $logger->error($error, ['name' => $filter_name, 'value' => $filter[$filter_name]]);
                    return ['success' => false, 'response' => $error];
                }
            } else {
                if (!is_numeric($filter['filter_rule'])) {
                    $error = "Malformed value for filter";
                    $logger->error($error, ['name' => 'filter_rule', 'value' => $filter['filter_rule']]);
                    return ['success' => false, 'response' => $error];
                }

                if ($filter['filter_rule'] < 0) {
                    $error = "Value should be > 0";
                    $logger->error($error, ['name' => 'filter_rule', 'value' => $filter['filter_rule']]);
                    return ['success' => false, 'response' => $error];
                }
            }
        }

        // некорректная дата
        $date_release = DateTime::createFromFormat('d.m.Y', $filter['filter_date_release']);
        if (!$date_release) {
            $error = "Malformed date for release";
            $logger->error($error, ['date_release' => $filter['filter_date_release']]);
            return ['success' => false, 'response' => $error];
        }

        // хранимые подразделы
        if ($forum_id > 0) {
            $forumsIDs = [$forum_id];
        } elseif ($forum_id == -5) {
            $forumsIDs = $db->query_database(
                'SELECT DISTINCT ss FROM Topics WHERE pt = 2',
                [],
                true,
                PDO::FETCH_COLUMN
            );
            if (empty($forumsIDs)) {
                $forumsIDs = [0];
            }
        } else {
            if (isset($cfg['subsections'])) {
                foreach ($cfg['subsections'] as $forum_id => $subsection) {
                    if (!$subsection['hide_topics']) {
                        $forumsIDs[] = $forum_id;
                    }
                }
            } else {
                $forumsIDs = [0];
            }
        }

        $ss = str_repeat('?,', count($forumsIDs) - 1) . '?';
        $st = str_repeat('?,', count($filter['filter_tracker_status']) - 1) . '?';
        $torrentDone = 'CAST(done as INT) IS ' . implode(' OR CAST(done AS INT) IS ', $filter['filter_client_status']);

        // 1 - fields, 2 - left join, 3 - where
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
                Torrents.error
                %s
            FROM Topics
            LEFT JOIN Torrents ON Topics.hs = Torrents.info_hash
            %s
            LEFT JOIN (
                SELECT
                    id,
                    nick,
                    MAX(posted) as posted,
                    complete,
                    MAX(seeding) as seeding
                FROM (
                    SELECT
                        Topics.id,
                        Keepers.nick,
                        complete,posted,
                        NULL as seeding
                    FROM Topics
                    LEFT JOIN Keepers ON Topics.id = Keepers.id
                    WHERE Keepers.id IS NOT NULL
                    UNION ALL
                    SELECT
                        topic_id,
                        nick,
                        1,
                        NULL,
                        1
                    FROM Topics
                    LEFT JOIN KeepersSeeders ON Topics.id = KeepersSeeders.topic_id
                    WHERE KeepersSeeders.topic_id IS NOT NULL
                ) GROUP BY id
            ) Keepers ON Topics.id = Keepers.id
            LEFT JOIN (SELECT info_hash FROM TopicsExcluded GROUP BY info_hash) TopicsExcluded ON Topics.hs = TopicsExcluded.info_hash
            WHERE
                ss IN (' . $ss . ')
                AND st IN (' . $st . ')
                AND (' . $torrentDone . ')
                AND TopicsExcluded.info_hash IS NULL
                %s';

        $fields = [];
        $where = [];
        $left_join = [];

        if ($cfg['avg_seeders']) {
            // некорректный период средних сидов
            if (!is_numeric($filter['avg_seeders_period'])) {
                $error = "Incorrect value for period to compute mean seeders";
                $logger->error($error, ['avg_seeders_period' => $filter['avg_seeders_period']]);
                return ['success' => false, 'response' => $error];
            }
            // жёсткое ограничение на 30 дней для средних сидов
            $filter['avg_seeders_period'] = $filter['avg_seeders_period'] > 0 ? $filter['avg_seeders_period'] : 1;
            $filter['avg_seeders_period'] = min($filter['avg_seeders_period'], 30);
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
            $keepers += $db->query_database(
                'SELECT k.id,k.nick,MAX(k.complete) as complete,MAX(k.posted) as posted,MAX(k.seeding) as seeding FROM (
                    SELECT Topics.id,Keepers.nick,complete,posted,NULL as seeding FROM Topics
                    LEFT JOIN Keepers ON Topics.id = Keepers.id
                    WHERE ss IN (' . $ss . ') AND rg < posted AND Keepers.id IS NOT NULL
                    UNION ALL
                    SELECT topic_id,nick,1 as complete,NULL as posted,1 as seeding FROM Topics
                    LEFT JOIN KeepersSeeders ON Topics.id = KeepersSeeders.topic_id
                    WHERE ss IN (' . $ss . ') AND KeepersSeeders.topic_id IS NOT NULL
                ) as k
                GROUP BY id, nick',
                array_merge($forumsIDsChunk, $forumsIDsChunk),
                true,
                PDO::FETCH_ASSOC | PDO::FETCH_GROUP
            );
        }
        $statement = sprintf(
            $pattern_statement,
            ',' . implode(',', $fields),
            ' ' . implode(' ', $left_join),
            ' ' . implode(' ', $where)
        );

        // из базы
        $topics = $db->query_database(
            $statement,
            array_merge(
                $forumsIDs,
                $filter['filter_tracker_status']
            ),
            true
        );

        // сортировка раздач
        $topics = Utils::natsort_field(
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
            $filterByKeeper = explode(',', $filter['filter_phrase']);
        }

        // выводим раздачи
        foreach ($topics as $topic_id => $topic_data) {
            // фильтрация по приоритету
            if (!in_array($topic_data['pt'], $filter['keeping_priority'])) {
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
            $keepers_list = '';
            if (isset($keepers[$topic_data['id']])) {
                $formatKeeperList = '<i class="fa fa-%1$s text-%2$s"></i> <i class="keeper bold text-%2$s">%3$s</i>';
                $keepers_list = array_map(function ($e) use ($formatKeeperList) {
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
                    return sprintf(
                        $formatKeeperList,
                        $stateKeeperIcon,
                        $stateKeeperColor,
                        $e['nick']
                    );
                }, $keepers[$topic_data['id']]);
                $keepers_list = '| ' . implode(', ', $keepers_list);
            }
            // фильтрация по фразе
            if (!empty($filter['filter_phrase'])) {
                if ($filter['filter_by_phrase'] == 0) { // в имени хранителя
                    if (!isset($keepers[$topic_data['id']])) {
                        $keepers[$topic_data['id']] = [];
                    }
                    $topicKeepers = array_column($keepers[$topic_data['id']], 'nick');
                    unset($matchKeepers);
                    foreach ($filterByKeeper as $filterKeeper) {
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
                }
            }

            if (
                isset($filter['is_keepers'])
                && (
                    $filter['keepers_filter_rule_interval']['from'] > count($keepers[$topic_data['id']])
                    || $filter['keepers_filter_rule_interval']['to'] < count($keepers[$topic_data['id']])
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
            if ($topic_data['done'] == 1) {
                $stateTorrentClient = 'fa-arrow-up';
            } elseif ($topic_data['done'] == null) {
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
            $bullet = '';
            if (isset($topic_data['ds'])) {
                if ($topic_data['ds'] < $filter['avg_seeders_period']) {
                    $bullet = $topic_data['ds'] >= $filter['avg_seeders_period'] / 2 ? 'text-warning' : 'text-danger';
                } else {
                    $bullet = 'text-success';
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
                    Utils::convert_bytes($topic_data['si']),
                    date('d.m.Y', $topic_data['rg']),
                    round($topic_data['se'], 2),
                    $bullet,
                    $stateTorrentClient
                ),
                $keepers_list
            );
        }
    }

    return
        [
            'success' => true,
            'response' => [
                'topics' => $output,
                'size' => $filtered_topics_size,
                'count' => $filtered_topics_count,
            ]
        ];
}
