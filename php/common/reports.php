<?php

$starttime = microtime(true);

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/reports.php';
include_once dirname(__FILE__) . '/../classes/user_details.php';

Log::append("Начат процесс отправки отчётов...");

// получение настроек
$cfg = get_settings();

// проверка настроек
if (empty($cfg['subsections'])) {
    throw new Exception("Error: Не выбраны хранимые подразделы");
}

if (empty($cfg['tracker_login'])) {
    throw new Exception("Error: Не указано имя пользователя для доступа к форуму");
}

if (empty($cfg['tracker_paswd'])) {
    throw new Exception("Error: Не указан пароль пользователя для доступа к форуму");
}

// update_time[0] время последнего обновления сведений
$update_time = Db::query_database(
    "SELECT ud FROM UpdateTime WHERE id = 7777",
    array(),
    true,
    PDO::FETCH_COLUMN
);

if (empty($update_time[0])) {
    throw new Exception("Error: Отсутствует метка времени последнего обновления сведений");
}

// исключаемые подразделы
$excludeForumsIDs = explode(',', $cfg['reports']['exclude_forums_ids']);

// const & pattern
$message_length_max = 119000;
$pattern_topic = '[url=viewtopic.php?t=%s]%s[/url] %s';
$pattern_spoiler = '[spoiler="№№ %s — %s"][list=1][*=%s]%s[/list][/spoiler]';
$pattern_common = '[url=viewtopic.php?t=%s][u]%s[/u][/url] — %s шт. (%s)';
$pattern_header = 'Хранитель %s: [url=profile.php?mode=viewprofile&u=%s&name=1][u][color=#006699]%s[/u][/color][/url] [color=gray]~>[/color] %s шт. [color=gray]~>[/color] %s[br]';
$spoiler_length = mb_strlen($pattern_spoiler, 'UTF-8');

// общий объём и количество хранимого в сводный
$sumdlqt = 0;
$sumdlsi = 0;

// список подразделов в сводный
$common_forums = array();

// подключаемся к форуму
$reports = new Reports(
    $cfg['forum_address'],
    $cfg['tracker_login'],
    $cfg['tracker_paswd']
);

// применяем таймауты
$reports->curl_setopts($cfg['curl_setopt']['forum']);

foreach ($cfg['subsections'] as $forum_id => $subsection) {
    // Log::append("Отправка отчётов для подраздела № $forum_id...");

    // исключаем подразделы
    if (in_array($forum_id, $excludeForumsIDs)) {
        continue;
    }

    // получение данных о подразделе
    $forum = Db::query_database(
        "SELECT * FROM Forums WHERE id = ?",
        array($forum_id),
        true,
        PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE
    );

    if (empty($forum)) {
        Log::append("Error: Не получены данные о хранимом подразделе № $forum_id");
        continue;
    }

    // получение данных о раздачах
    $topics = Db::query_database(
        'SELECT
            Topics.id,
            Topics.ss,
            Topics.na,
            Topics.si,
            Topics.st,
            Torrents.done
        FROM Topics
        LEFT JOIN (
            SELECT
                info_hash,
                MAX(done) AS done
            FROM Torrents
            WHERE error = 0
            GROUP BY info_hash
        ) Torrents ON Topics.hs = Torrents.info_hash
        WHERE
            Torrents.info_hash IS NOT NULL
            AND Topics.ss = ?
            AND Topics.se / Topics.qt <= 10',
        array($forum_id),
        true
    );

    if (empty($topics)) {
        Log::append("Notice: Не получены данные о хранимых раздачах для подраздела № $forum_id");
        continue;
    }

    // сортировка раздач
    $topics = natsort_field($topics, 'na');

    // количество раздач
    $topics_count = count($topics);

    // очищаем данные в цикле
    $posts_ids = array();
    $stored = array();
    $tmp = array();

    // формируем списки
    foreach ($topics as $topic) {
        if (empty($tmp)) {
            $tmp['start'] = 1;
            $tmp['lgth'] = 0;
            $tmp['dlsi'] = 0;
            $tmp['dlqt'] = 0;
            $tmp['dlsisub'] = 0;
            $tmp['dlqtsub'] = 0;
        }
        $topicLink = $topic['done'] != 1 ? $topic['id'] . '#dl' : $topic['id'];
        $str = sprintf(
            $pattern_topic,
            $topicLink,
            $topic['na'],
            convert_bytes($topic['si'])
        );
        if ($topic['done'] != 1) {
            $tmp['dlqtsub']++;
            $tmp['dlsisub'] += $topic['si'];
            $str .= ' :!: ';
        } else {
            $tmp['dlsi'] += $topic['si'];
        }
        $tmp['dlqt']++;
        $lgth = mb_strlen($str, 'UTF-8');
        $tmp['str'][] = $str;
        $tmp['lgth'] += $lgth;
        $current_length = $tmp['lgth'] + $lgth;
        $available_length = $message_length_max - $spoiler_length - ($tmp['dlqt'] - $tmp['start'] + 1) * 3;
        if (
            $current_length > $available_length
            || $tmp['dlqt'] == $topics_count
        ) {
            $tmp['str'] = implode('[*]', $tmp['str']);
            $tmp['msg'][] = sprintf(
                $pattern_spoiler,
                $tmp['start'],
                $tmp['dlqt'],
                $tmp['start'],
                $tmp['str']
            );
            $tmp['start'] = $tmp['dlqt'] + 1;
            $tmp['lgth'] = 0;
            unset($tmp['str']);
        }
    }
    unset($topics);

    if (empty($tmp['msg'])) {
        Log::append("Error: Не удалось сформировать список хранимого для подраздела № $forum_id");
        continue;
    }

    // вычитаем раздачи на загрузке
    $tmp['dlqt'] -= $tmp['dlqtsub'];

    // дописываем в начало первого сообщения
    $tmp['msg'][0] = 'Актуально на: [color=darkblue]' . date('d.m.Y', $update_time[0]) . '[/color][br]' .
        'Всего хранимых раздач в подразделе: ' . $tmp['dlqt'] . ' шт. / ' . convert_bytes($tmp['dlsi']) . '[br]' .
        'Всего скачиваемых раздач в подразделе: ' . $tmp['dlqtsub'] . ' шт. / ' . convert_bytes($tmp['dlsisub']) . '[br]' .
        $webtlo->version_line .
        $tmp['msg'][0];

    // ищем тему со списками
    $topicID = $reports->search_topic_id($forum[$forum_id]['na']);

    if (empty($topicID)) {
        Log::append("Error: Не удалось найти тему со списком для подраздела № $forum_id");
        continue;
    }

    // сохраним все редактирумые темы
    $editedTopicsIDs[] = $topicID;

    // Log::append("Сканирование списков...");

    // сканируем имеющиеся списки
    $keepers = $reports->scanning_viewtopic($topicID);

    if ($keepers !== false) {
        // разбираем инфу, полученную из списков
        foreach ($keepers as $index => $keeper) {
            $posted = $keeper['posted'];
            // array( 'post_id', 'nickname', 'posted', 'topics_ids' => array(...) )
            if ($index == 0) {
                $postIDAuthorTopic = $keeper['post_id'];
                $nicknameAuthorTopic = $keeper['nickname'];
                continue;
            }
            // запоминаем свои сообщения
            if (strcasecmp($cfg['tracker_login'], $keeper['nickname']) === 0) {
                $posts_ids[] = $keeper['post_id'];
                continue;
            }
            // если хранитель не является автором шапки
            if (strcasecmp($cfg['tracker_login'], $nicknameAuthorTopic) !== 0) {
                continue;
            }
            // считаем сообщения других хранителей в подразделе
            if (!isset($stored[$keeper['nickname']])) {
                $stored[$keeper['nickname']]['dlqt'] = 0;
                $stored[$keeper['nickname']]['dlsi'] = 0;
                $stored[$keeper['nickname']]['dlqtsub'] = 0;
                $stored[$keeper['nickname']]['dlsisub'] = 0;
            }
            if (empty($keeper['topics_ids'])) {
                continue;
            }
            foreach ($keeper['topics_ids'] as $index => $keeperTopicsIDs) {
                $topics_ids = array_chunk($keeperTopicsIDs, 500);
                foreach ($topics_ids as $topics_ids) {
                    $in = str_repeat('?,', count($topics_ids) - 1) . '?';
                    $values = Db::query_database(
                        "SELECT COUNT(),SUM(si) FROM Topics
                            WHERE id IN ($in) AND ss = $forum_id AND rg < CAST($posted as INTEGER)",
                        $topics_ids,
                        true,
                        PDO::FETCH_NUM
                    );
                    if ($index == 1) {
                        $stored[$keeper['nickname']]['dlqt'] += $values[0][0];
                        $stored[$keeper['nickname']]['dlsi'] += $values[0][1];
                    } else {
                        $stored[$keeper['nickname']]['dlqtsub'] += $values[0][0];
                        $stored[$keeper['nickname']]['dlsisub'] += $values[0][1];
                    }
                    unset($values);
                }
                unset($topics_ids);
            }
        }
    }

    // Log::append("Найдено своих сообщений: " . count($posts_ids));

    // вставка доп. сообщений
    if (count($tmp['msg']) > count($posts_ids)) {
        $count_post_reply = count($tmp['msg']) - count($posts_ids);
        for ($i = 1; $i <= $count_post_reply; $i++) {
            // Log::append("Вставка дополнительного $i-ого сообщения...");
            $message = '[spoiler]' . $i . str_repeat('?', 119981 - mb_strlen($i)) . '[/spoiler]';
            $posts_ids[] = $reports->send_message(
                'reply',
                $message,
                $topicID
            );
            usleep(1500);
        }
    }

    // редактирование сообщений
    foreach ($posts_ids as $index => $post_id) {
        $post_number = $index + 1;
        // Log::append("Редактирование сообщения № $post_number...");
        $message = empty($tmp['msg'][$index]) ? 'резерв' : $tmp['msg'][$index];
        $reports->send_message(
            'editpost',
            $message,
            $topicID,
            $post_id
        );
    }

    // работа с шапкой
    if (strcasecmp($cfg['tracker_login'], $nicknameAuthorTopic) === 0) {
        $tmp['header'] = '[url=viewforum.php?f=' . $forum_id . '][u][color=#006699]' . preg_replace('/.*» ?(.*)$/', '$1', $forum[$forum_id]['na']) . '[/u][/color][/url] ' .
            '| [url=tracker.php?f=' . $forum_id . '&tm=-1&o=10&s=1&oop=1][color=indigo][u]Проверка сидов[/u][/color][/url][br][br]' .
            'Актуально на: [color=darkblue]' . date('d.m.Y', $update_time[0]) . '[/color][br]' .
            'Всего раздач в подразделе: ' . $forum[$forum_id]['qt'] . ' шт. / ' . convert_bytes($forum[$forum_id]['si']) . '[br]' .
            'Всего хранимых раздач в подразделе: %%dlqt%% шт. / %%dlsi%%[br]' .
            'Всего скачиваемых раздач в подразделе: %%dlqtsub%% шт. / %%dlsisub%%[br]' .
            'Количество хранителей: %%kpqt%%[hr]' .
            'Хранитель 1: [url=profile.php?mode=viewprofile&u=' . urlencode($nicknameAuthorTopic) . '&name=1][u][color=#006699]' . $nicknameAuthorTopic . '[/u][/color][/url] [color=gray]~>[/color] ' . $tmp['dlqt'] . ' шт. [color=gray]~>[/color] ' . convert_bytes($tmp['dlsi']) . '[br]';
        // значения хранимого для шапки
        $count_keepers = 1;
        $sumdlqt_keepers = $tmp['dlqt'];
        $sumdlsi_keepers = $tmp['dlsi'];
        $sumdlqtsub_keepers = $tmp['dlqtsub'];
        $sumdlsisub_keepers = $tmp['dlsisub'];
        // учитываем хранимое другими
        foreach ($stored as $nickname => $values) {
            $count_keepers++;
            $tmp['header'] .= sprintf(
                $pattern_header,
                $count_keepers,
                urlencode($nickname),
                $nickname,
                $values['dlqt'],
                convert_bytes($values['dlsi'])
            );
            $sumdlqt_keepers += $values['dlqt'];
            $sumdlsi_keepers += $values['dlsi'];
            $sumdlqtsub_keepers += $values['dlqtsub'];
            $sumdlsisub_keepers += $values['dlsisub'];
        }
        // вставляем общее хранимое в шапку
        $tmp['header'] = str_replace(
            array(
                '%%dlqt%%',
                '%%dlsi%%',
                '%%dlqtsub%%',
                '%%dlsisub%%',
                '%%kpqt%%',
            ),
            array(
                $sumdlqt_keepers,
                convert_bytes($sumdlsi_keepers),
                $sumdlqtsub_keepers,
                convert_bytes($sumdlsisub_keepers),
                $count_keepers,
            ),
            $tmp['header']
        );
        // Log::append('Отправка шапки...');
        // отправка сообщения с шапкой
        $reports->send_message(
            'editpost',
            $tmp['header'],
            $topicID,
            $postIDAuthorTopic,
            '[Список] ' . $subsection['na']
        );
    }

    // добавляем информацию в сводный отчёт
    $sumdlqt += $tmp['dlqt'];
    $sumdlsi += $tmp['dlsi'];
    $common_forums[$forum_id] = sprintf(
        $pattern_common,
        $topicID,
        $forum[$forum_id]['na'],
        $tmp['dlqt'],
        convert_bytes($tmp['dlsi'])
    );
}

Log::append("Обработано подразделов: " . count($common_forums) . " шт.");

// работаем со сводным отчётом
if ($cfg['reports']['send_summary_report']) {
    // формируем сводный отчёт
    $common = 'Актуально на: [b]' . date('d.m.Y', $update_time[0]) . '[/b][br][br]' .
        'Общее количество хранимых раздач: [b]' . $sumdlqt . '[/b] шт.[br]' .
        'Общий вес хранимых раздач: [b]' . preg_replace('/ (?!.* )/', '[/b] ', convert_bytes($sumdlsi)) . '[br]' .
        $webtlo->version_line_url . '[hr]' .
        implode('[br]', $common_forums);
    // ищем сообщение со сводным
    $post_id = $reports->search_post_id(4275633, true);
    $common_mode = empty($post_id) ? 'reply' : 'editpost';
    Log::append("Отправка сводного отчёта...");
    // отправляем сводный отчёт
    $reports->send_message(
        $common_mode,
        $common,
        4275633,
        $post_id
    );
}

// отредактируем все сторонние темы со своими сообщениями в рабочем подфоруме
if (
    $cfg['reports']['auto_clear_messages']
    && !empty($editedTopicsIDs)
) {
    $topicsIDsWithMyMessages = $reports->searchTopicsIDs(array('uid' => $cfg['user_id']));
    $uneditedTopicsIDs = array_diff($topicsIDsWithMyMessages, $editedTopicsIDs);
    if (!empty($uneditedTopicsIDs)) {
        foreach ($uneditedTopicsIDs as $topicID) {
            $messages = $reports->scanning_viewtopic($topicID);
            if ($messages === false) {
                continue;
            }
            foreach ($messages as $index => $message) {
                // пропускаем шапку
                if ($index == 0) {
                    continue;
                }
                // только свои сообщения
                if (strcasecmp($cfg['tracker_login'], $message['nickname']) === 0) {
                    $reports->send_message('editpost', ':!: не актуально', $topicID, $message['post_id']);
                }
            }
        }
    }
}

$endtime = microtime(true);

Log::append("Завершён процесс отправки отчётов за " . convert_seconds($endtime - $starttime));
