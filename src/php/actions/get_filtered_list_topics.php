<?php

use KeepersTeam\Webtlo\TopicList\Helper;
use KeepersTeam\Webtlo\TopicList\Validate;
use KeepersTeam\Webtlo\TopicList\Module;
use KeepersTeam\Webtlo\TopicList\State;
use KeepersTeam\Webtlo\TopicList\Topic;
use KeepersTeam\Webtlo\TopicList\TopicPattern;

try {
    include_once dirname(__FILE__) . '/../common.php';

    $forum_id = $_POST['forum_id'] ?? null;
    if (!is_numeric($forum_id)) {
        throw new Exception("Некорректный идентификатор подраздела: $forum_id");
    }
    $forum_id = (int)$forum_id;

    // получаем настройки
    $cfg = get_settings();
    $user_id = (int)$cfg['user_id'];

    // кодировка для regexp
    mb_regex_encoding('UTF-8');

    // парсим параметры фильтра
    $filter = [];
    parse_str($_POST['filter'], $filter);

    Validate::sortFilter($filter);

    // 0 - из других подразделов
    // -1 - незарегистрированные
    // -2 - черный список
    // -3 - все хранимые
    // -4 - дублирующиеся раздачи
    // -5 - высокоприоритетные раздачи
    // -6 - раздачи своим по спискам

    $topicPattern = new TopicPattern($cfg, $cfg['forum_address'] ?? '');

    $module = new Module($cfg, $topicPattern);

    $keepersFilter = prepareKeepersFilter($filter);

    $preparedOutput = [];
    $filtered_topics_count = 0;
    $filtered_topics_size = 0;
    $excluded_topics = ["ex_count" => 0, "ex_size" => 0];

    // Хранимые раздачи из других подразделов.
    if ($forum_id === 0) {
        [$preparedOutput, $filtered_topics_count, $filtered_topics_size] = $module->getUntrackedTopics($filter);
    }
    // Хранимые раздачи незарегистрированные на трекере.
    elseif ($forum_id === -1) {
        [$preparedOutput, $filtered_topics_count, $filtered_topics_size] = $module->getUnregisteredTopics();
    }
    // Раздачи из "Черного списка".
    elseif ($forum_id === -2) {
        [$preparedOutput, $filtered_topics_count, $filtered_topics_size] = $module->getBlackListedTopics($filter);
    }
    // Хранимые дублирующиеся раздачи.
    elseif ($forum_id === -4) {
        // Данные для фильтрации по средним сидам.
        $averagePeriodFilter = prepareAveragePeriodFilter($filter, $cfg);

        [$preparedOutput, $filtered_topics_count, $filtered_topics_size] = $module->getDuplicatedTopics($filter, $averagePeriodFilter);
    }
    // Основной поиск раздач.
    elseif (
        $forum_id > 0        // заданный раздел
        || $forum_id === -3  // все хранимые подразделы
        || $forum_id === -5  // высокий приоритет
        || $forum_id === -6  // все хранимые подразделы по спискам
    ) {
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

        // Некорректный ввод значения сидов или количества хранителей
        Validate::filterRuleIntervals($filter);

        // Данные для фильтрации по средним сидам.
        $averagePeriodFilter = prepareAveragePeriodFilter($filter, $cfg);

        // некорректная дата
        $date_release = DateTime::createFromFormat('d.m.Y', $filter['filter_date_release']);
        if (!$date_release) {
            throw new Exception('В фильтре введена некорректная дата создания релиза');
        }

        // Исключить себя из списка хранителей.
        $exclude_self_keep = $cfg['exclude_self_keep'];

        // хранимые подразделы
        $forumsIDs = getForumIdList($cfg, $forum_id);

        // Шаблоны для подразделов, статусов раздач, приоритета хранения.
        $ss = str_repeat('?,', count($forumsIDs) - 1) . '?';
        $st = str_repeat('?,', count($filter['filter_tracker_status']) - 1) . '?';
        $pt = str_repeat('?,', count($filter['keeping_priority']) - 1) . '?';
        // Шаблон для статуса хранения.
        $torrentDone = 'CAST(done as INT) IS ' . implode(' OR CAST(done AS INT) IS ', $filter['filter_client_status']);


        // Данный подзапрос, для каждой раздачи, определяет наличие:
        // max_posted - хранителя включившего раздачу в отчёт, по данным форума (KeepersLists);
        // has_complete - хранителя завершившего скачивание раздачи;
        // has_download - хранителя скачивающего раздачу;
        // has_seeding - хранителя раздающего раздачу, по данным апи (KeepersSeeders);
        $keepers_status_statement = sprintf(
            '
                SELECT topic_id,
                    MAX(complete) AS has_complete,
                    MAX(posted) AS max_posted,
                    MAX(NOT complete) AS has_download,
                    MAX(seeding) AS has_seeding
                FROM (
                    SELECT topic_id, MAX(complete) AS complete, MAX(posted) AS posted, MAX(seeding) AS seeding
                    FROM (
                        SELECT topic_id, keeper_id, complete, posted, 0 AS seeding
                        FROM KeepersLists
                        UNION ALL
                        SELECT topic_id, keeper_id, 1 AS complete, NULL AS posted, 1 AS seeding
                        FROM KeepersSeeders
                    )
                    %s
                    GROUP BY topic_id, keeper_id
                )
                GROUP BY topic_id
            ',
            // Исключаем себя из списка, при необходимости
            $exclude_self_keep ? "WHERE keeper_id != '$user_id'" : ''
        );

        // 1 - fields, 2 - left join, 3 - keepers check, 4 - where
        $statement = "
            SELECT
                Topics.id,
                Topics.hs,
                Topics.na,
                Topics.si,
                Topics.rg,
                Topics.ss,
                Topics.pt,
                Torrents.done,
                Torrents.paused,
                Torrents.error,
                Torrents.client_id as cl,
                %s
            FROM Topics
            LEFT JOIN Torrents ON Topics.hs = Torrents.info_hash
            %s
            LEFT JOIN (
                %s
            ) Keepers ON Topics.id = Keepers.topic_id AND (Keepers.max_posted IS NULL OR Topics.rg < Keepers.max_posted)
            LEFT JOIN (SELECT info_hash FROM TopicsExcluded GROUP BY info_hash) TopicsExcluded ON Topics.hs = TopicsExcluded.info_hash
            WHERE
                ss IN ($ss)
                AND st IN ($st)
                AND pt IN ($pt)
                AND ($torrentDone)
                AND TopicsExcluded.info_hash IS NULL
                %s
        ";

        // Данные о средних сидах.
        [$fields, $left_join] = prepareAverageQueryParam($averagePeriodFilter);

        // Применить фильтр по статусу хранимого.
        $where = getKeptStatusFilter($keepersFilter);

        // 1 - fields, 2 - left join, 3 - keepers check, 4 - where
        $statement = sprintf(
            $statement,
            implode(',', $fields),
            implode(' ', $left_join),
            $keepers_status_statement,
            implode(' ', $where)
        );

        // из базы
        $topics = (array)Db::query_database(
            $statement,
            array_merge(
                $forumsIDs,
                $filter['filter_tracker_status'],
                $filter['keeping_priority'],
            ),
            true
        );
        $topics = Helper::topicsSortByFilter($topics, $filter);


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

        // Данные о других хранителях.
        $keepers = getKeepersByForumList($forumsIDs, $ss, $user_id);

        // выводим раздачи
        foreach ($topics as $topicData) {
            // фильтрация по клиенту
            if ($filter['filter_client_id'] > 0 && $filter['filter_client_id'] != $topicData['cl']) {
                continue;
            }
            // фильтрация по дате релиза
            if ($topicData['rg'] > $date_release->format('U')) {
                continue;
            }

            // Фильтрация по количеству сидов.
            if (!isSeedCountInRange($filter, round($topicData['se'], 2))) {
                continue;
            }

            // фильтрация по статусу "зелёные"
            if (
                isset($topicData['ds'])
                && isset($filter['avg_seeders_complete'])
                && $filter['avg_seeders_period'] > $topicData['ds']
            ) {
                continue;
            }
            // список хранителей на раздаче
            $topic_keepers = $keepers[$topicData['id']] ?? [];
            // фильтрация раздач по своим спискам
            if ($forum_id == -6) {
                $exclude_self_keep = 0;
                $topicKeepers = array_column($topic_keepers, 'keeper_id');
                if (!count($topicKeepers) || !in_array($user_id, $topicKeepers)) {
                    continue;
                }
            }
            // исключим себя из списка хранителей раздачи
            if ($exclude_self_keep) {
                $topic_keepers =  array_filter($topic_keepers, function ($e) use ($user_id) {
                    return $user_id !== (int)$e['keeper_id'];
                });
            }

            // фильтрация по фразе
            if (!empty($filter['filter_phrase'])) {
                if ($filter['filter_by_phrase'] == 0) { // в имени хранителя
                    $topicKeepers = array_column($topic_keepers, 'keeper_name');
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
                    if (!mb_eregi($filterByTopicName, $topicData['na'])) {
                        continue;
                    }
                } elseif ($filter['filter_by_phrase'] == 2) { // в номере/ид раздачи
                    $matchId = false;
                    foreach ($filterValues as $filterId) {
                        $filterId = sprintf("^%s$", str_replace('*', '.*', $filterId));
                        if (mb_eregi($filterId, $topicData['id'])) {
                            $matchId = true;
                        }
                    }
                    if (!$matchId) {
                        continue;
                    }
                }
            }

            // Фильтрация по количеству хранителей
            if (!isTopicKeepersInRange($keepersFilter, $topic_keepers)) {
                continue;
            }

            $filtered_topics_count++;
            $filtered_topics_size += $topicData['si'];

            // Состояние раздачи в клиенте (пулька) [иконка, цвет, описание].
            $topicState = State::parseFromTorrent(
                $topicData,
                $averagePeriodFilter['seedPeriod'],
                $topicData['ds']
            );

            $topicObject = new Topic(
                $topicData['id'],
                $topicData['hs'],
                $topicData['na'],
                $topicData['si'],
                Helper::setTimestamp((int)$topicData['rg']),
                $topicData['ss'],
                round($topicData['se'], 2),
                $topicData['pt'],
                $topicState,
                $topicData['cl'] ?? null,
            );

            // Форматированный список хранителей раздачи.
            $topicKeepers = Helper::getFormattedKeepersList($topic_keepers, $user_id);

            // Выводим строку с данными раздачи.
            $preparedOutput[] = $topicPattern->getFormatted($topicObject, $topicKeepers);
        }

        $excluded = (array)Db::query_database_row(
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
        if (count($excluded)) {
            $excluded_topics = $excluded;
        }
    }

    echo json_encode([
        'log' => '',
        'topics' => implode('', $preparedOutput),
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

function getForumIdList(array $cfg, int $forum_id): array
{
    $forumsIDs = [0];
    if ($forum_id > 0) {
        $forumsIDs = [$forum_id];
    } elseif ($forum_id === -5) {
        $forumsIDs = (array)Db::query_database(
            'SELECT DISTINCT ss FROM Topics WHERE pt = 2',
            [],
            true,
            PDO::FETCH_COLUMN
        );
    } else {
        // -3 || -6
        if (isset($cfg['subsections'])) {
            foreach ($cfg['subsections'] as $sub_forum_id => $subsection) {
                if (!$subsection['hide_topics']) {
                    $forumsIDs[] = $sub_forum_id;
                }
            }
        }
    }
    if (empty($forumsIDs)) {
        $forumsIDs = [0];
    }

    return $forumsIDs;
}

/** Собрать параметры фильтрации по типам хранителей. */
function prepareKeepersFilter(array $filter): array
{
    $topic_kept_status_keys = ['filter_status_has_keeper', 'filter_status_has_seeder', 'filter_status_has_downloader'];

    $filter1 = array_combine(
        $topic_kept_status_keys,
        array_map(fn($el) => (int)($filter[$el] ?? -1), $topic_kept_status_keys)
    );

    $keepers_count_keys = ['is_keepers', 'keepers_count_seed', 'keepers_count_download', 'keepers_count_kept', 'keepers_count_kept_seed'];

    $filter2 = array_combine(
        $keepers_count_keys,
        array_map(fn($el) => (bool)($filter[$el] ?? false), $keepers_count_keys)
    );

    $keeper_filter = array_merge($filter1, $filter2);

    $keeper_filter['keepers_min'] = (int)($filter['keepers_filter_count']['min'] ?? 1);
    $keeper_filter['keepers_max'] = (int)($filter['keepers_filter_count']['max'] ?? 10);

    return $keeper_filter;
}

/**
 * Собрать параметры для работы со средними сидами.
 * @throws Exception
 */
function prepareAveragePeriodFilter(array $filter, array $cfg): array
{
    $useAvgSeeders = (bool)($cfg['avg_seeders'] ?? false);
    if ($useAvgSeeders) {
        // Проверка периода средних сидов.
        if (!is_numeric($filter['avg_seeders_period'])) {
            throw new Exception('В фильтре введено некорректное значение для периода средних сидов');
        }
    }

    // Жёсткое ограничение от 1 до 30 дней для средних сидов.
    return [
        'useAverage' => $useAvgSeeders,
        'seedPeriod' => min(max((int)$filter['avg_seeders_period'], 1), 30),
    ];
}

/** Подготовить части запросов БД при поиске средних сидов. */
function prepareAverageQueryParam(array $avgPeriodFilter): array
{
    $fields = $leftJoin = [];
    // Применить фильтр средних сидов.
    if ($avgPeriodFilter['useAverage']) {
        $temp = [];
        for ($i = 0; $i < $avgPeriodFilter['seedPeriod']; $i++) {
            $temp['sum_se'][] = "CASE WHEN d$i IS '' OR d$i IS NULL THEN 0 ELSE d$i END";
            $temp['sum_qt'][] = "CASE WHEN q$i IS '' OR q$i IS NULL THEN 0 ELSE q$i END";
            $temp['qt'][]     = "CASE WHEN q$i IS '' OR q$i IS NULL THEN 0 ELSE 1 END";
        }

        $qt     = implode('+', $temp['qt']);
        $sum_qt = implode('+', $temp['sum_qt']);
        $sum_se = implode('+', $temp['sum_se']);

        $fields[] = "$qt as ds";
        $fields[] = "CASE WHEN $qt IS 0 THEN (se * 1.) / qt ELSE ( se * 1. + $sum_se) / ( qt + $sum_qt) END as se";

        $leftJoin[] = 'LEFT JOIN Seeders ON Topics.id = Seeders.id';
    } else {
        $fields[] = '-1 AS ds';
        $fields[] = 'Topics.se';
    }

    return [$fields, $leftJoin];
}

/** Фильтр раздач по статусу хранения. */
function getKeptStatusFilter(array $keepersFilter): array
{
    $filter = [];
    // Фильтр "Хранитель с отчётом" = "да"/"нет"
    if ($keepersFilter['filter_status_has_keeper'] === 1) {
        $filter[] = 'AND Keepers.max_posted IS NOT NULL';
    }
    elseif ($keepersFilter['filter_status_has_keeper'] === 0) {
        $filter[] = 'AND Keepers.max_posted IS NULL';
    }

    // Фильтр "Хранитель раздаёт" = "да"/"нет"
    if ($keepersFilter['filter_status_has_seeder'] === 1) {
        $filter[] = 'AND Keepers.has_seeding = 1';
    }
    elseif ($keepersFilter['filter_status_has_seeder'] === 0) {
        $filter[] = 'AND (Keepers.has_seeding = 0 OR Keepers.has_seeding IS NULL)';
    }

    // Фильтр "Хранитель скачивает" = "да"/"нет"
    if ($keepersFilter['filter_status_has_downloader'] === 1) {
        $filter[] = 'AND Keepers.has_download = 1';
    }
    elseif ($keepersFilter['filter_status_has_downloader'] === 0) {
        $filter[] = 'AND (Keepers.has_download = 0 OR Keepers.has_download IS NULL)';
    }

    return $filter;
}

/** Попадает ли количество хранителей раздачи в заданные пределы по заданным правилам. */
function isTopicKeepersInRange(array $params, array $topicKeepers): bool
{
    if (!$params['is_keepers']) {
        return true;
    }

    $matchedKeepers = array_filter(
        $topicKeepers,
        function($kp) use ($params) {
            // Хранитель раздаёт.
            if ($params['keepers_count_seed'] && $kp['seeding'] === 1) {
                return true;
            }
            // Хранитель качает.
            if ($params['keepers_count_download'] && $kp['complete'] < 1) {
                return true;
            }
            // Хранитель хранит, не раздаёт.
            if ($params['keepers_count_kept'] && $kp['complete'] === 1 && $kp['posted'] > 0 && $kp['seeding'] === 0) {
                return true;
            }
            // Хранитель хранит и раздаёт.
            if ($params['keepers_count_kept_seed'] && $kp['complete'] === 1 && $kp['posted'] > 0 && $kp['seeding'] === 1) {
                return true;
            }

            return false;
        }
    );

    $keepersCount = count($matchedKeepers);

    return $params['keepers_min'] <= $keepersCount && $keepersCount <= $params['keepers_max'];
}

/** Попадает ли количество сидов раздачи в заданные пределы. */
function isSeedCountInRange(array $filter, float $topicSeeds): bool {
    $useInterval = (bool)($filter['filter_interval'] ?? false);
    if ($useInterval) {
        $min = (int)$filter['filter_rule_interval']['min'];
        $max = (int)$filter['filter_rule_interval']['max'];

        return $min <= $topicSeeds && $topicSeeds <= $max;
    }

    if ($filter['filter_rule_direction']) {
        return $filter['filter_rule'] > $topicSeeds;
    } else {
        return $filter['filter_rule'] < $topicSeeds;
    }
}

/** Список хранителей всех раздач указанных подразделов. */
function getKeepersByForumList(array $forumList, string $forumPlaceholder, int $user_id): array
{
    $keepers = [];
    foreach (array_chunk($forumList, 499) as $forumsChunk) {
        $keepers += Db::query_database(
            'SELECT k.topic_id, k.keeper_id, k.keeper_name, MAX(k.complete) AS complete, MAX(k.posted) AS posted, MAX(k.seeding) AS seeding 
                FROM (
                    SELECT kl.topic_id, kl.keeper_id, kl.keeper_name, kl.complete, kl.posted, 0 as seeding
                    FROM Topics
                    LEFT JOIN KeepersLists as kl ON Topics.id = kl.topic_id
                    WHERE ss IN (' . $forumPlaceholder . ') AND rg < posted AND kl.topic_id IS NOT NULL
                    UNION ALL
                    SELECT ks.topic_id, ks.keeper_id, ks.keeper_name, 1 as complete, 0 as posted, 1 as seeding
                    FROM Topics
                    LEFT JOIN KeepersSeeders as ks ON Topics.id = ks.topic_id
                    WHERE ss IN (' . $forumPlaceholder . ') AND ks.topic_id IS NOT NULL
                ) as k
                GROUP BY k.topic_id, k.keeper_id, k.keeper_name
                ORDER BY (CASE WHEN k.keeper_id == ? THEN 1 ELSE 0 END) DESC, complete DESC, seeding, posted DESC, k.keeper_name',
            array_merge($forumsChunk, $forumsChunk, [$user_id]),
            true,
            PDO::FETCH_ASSOC | PDO::FETCH_GROUP
        );
    }

    return $keepers;
}


