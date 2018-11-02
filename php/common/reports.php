<?php

$starttime = microtime(true);

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/reports.php';
include_once dirname(__FILE__) . '/../classes/user_details.php';

Log::append("Начато выполнение процесса отправки отчётов...");

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

// исключаемые подразделы
$exclude_forums = TIniFileEx::read('reports', 'exclude', '');
$exclude_forums = explode(',', $exclude_forums);

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

// update_time[0] время последнего обновления сведений
$update_time = Db::query_database(
    "SELECT ud FROM UpdateTime WHERE id = 7777",
    array(),
    true,
    PDO::FETCH_COLUMN
);

foreach ($cfg['subsections'] as $forum_id => $subsection) {

    Log::append("Отправка отчётов для подраздела № $forum_id...");

    // исключаем подразделы
    if (in_array($forum_id, $exclude_forums)) {
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
        "SELECT Topics.id,ss,na,si,st FROM Topics
		LEFT JOIN Clients ON Topics.hs = Clients.hs
		WHERE ss = ? AND dl IN (1,-1)",
        array($forum_id),
        true
    );

    if (empty($topics)) {
        Log::append("Error: Не получены данные о хранимых раздачах для подраздела № $forum_id");
        continue;
    }

    // сортировка раздач
    $topics = natsort_field($topics, 'na');

    // количество раздач
    $topics_count = count($topics);

    // формируем списки
    foreach ($topics as $topic) {
        if (empty($tmp)) {
            $tmp['start'] = 1;
            $tmp['lgth'] = 0;
            $tmp['dlsi'] = 0;
            $tmp['dlqt'] = 0;
        }
        $str = sprintf(
            $pattern_topic,
            $topic['id'],
            $topic['na'],
            convert_bytes($topic['si'])
        );
        $lgth = mb_strlen($str, 'UTF-8');
        $tmp['str'][] = $str;
        $tmp['lgth'] += $lgth;
        $tmp['dlsi'] += $topic['si'];
        $tmp['dlqt']++;
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
    unset($topics_count);
    unset($topics);

    if (empty($tmp['msg'])) {
        Log::append("Error: Не удалось сформировать список хранимого для подраздела № $forum_id");
        continue;
    }

    // дописываем в начало первого сообщения
    $tmp['msg'][0] = 'Актуально на: [color=darkblue]' . date('d.m.Y', $update_time[0]) . '[/color][br]' .
    'Всего хранимых раздач в подразделе: ' . $tmp['dlqt'] . ' шт. / ' . convert_bytes($tmp['dlsi']) .
        $tmp['msg'][0];

    if (!isset($reports)) {
        $reports = new Reports(
            $cfg['forum_url'],
            $cfg['tracker_login'],
            $cfg['tracker_paswd']
        );
    }

    // ищем тему со списками
    $topic_id = $reports->search_topic_id($forum[$forum_id]['na']);

    if (empty($topic_id)) {
        Log::append("Error: Не удалось найти тему со списком для подраздела № $forum_id");
        continue;
    }

    Log::append("Сканирование списков...");

    // сканируем имеющиеся списки
    $keepers = $reports->scanning_viewtopic($topic_id);

    if (empty($keepers)) {
        Log::append("Error: Не удалось просканировать списки для подраздела № $forum_id");
        continue;
    }

    // разбираем инфу, полученную из списков
    foreach ($keepers as $index => $keeper) {
        // array( 'post_id', 'nickname', 'posted', 'topics_ids' => array(...) )
        if ($index == 0) {
            $author_post_id = $keeper['post_id'];
            $author_nickname = $keeper['nickname'];
            continue;
        }
        // запоминаем свои сообщения
        if ($keeper['nickname'] == $cfg['tracker_login']) {
            $posts_ids[] = $keeper['post_id'];
            continue;
        }
        // считаем сообщения других хранителей в подразделе
        if (
            !empty($keeper['topics_ids'])
            && $cfg['tracker_login'] == $author_nickname
        ) {
            $topics_ids = array_chunk($keeper['topics_ids'], 500);
            foreach ($topics_ids as $topics_ids) {
                $in = str_repeat('?,', count($topics_ids) - 1) . '?';
                $values = Db::query_database(
                    "SELECT COUNT(),SUM(si) FROM Topics WHERE id IN ($in) AND ss = $forum_id",
                    $topics_ids,
                    true,
                    PDO::FETCH_NUM
                );
                if (!isset($stored[$keeper['nickname']])) {
                    $stored[$keeper['nickname']]['dlqt'] = 0;
                    $stored[$keeper['nickname']]['dlsi'] = 0;
                }
                $stored[$keeper['nickname']]['dlqt'] += $values[0][0];
                $stored[$keeper['nickname']]['dlsi'] += $values[0][1];
                unset($values);
            }
            unset($topics_ids);
        }
    }
    unset($keepers);

    Log::append("Найдено своих сообщений: " . count($posts_ids));

    // вставка доп. сообщений
    if (count($tmp['msg']) > count($posts_ids)) {
        $count_post_reply = count($tmp['msg']) - count($posts_ids);
        for ($i = 1; $i <= $count_post_reply; $i++) {
            Log::append("Вставка дополнительного $i-ого сообщения...");
            $message = '[spoiler]' . $i . str_repeat('?', 119981 - mb_strlen($i)) . '[/spoiler]';
            $posts_ids[] = $reports->send_message(
                'reply',
                $message,
                $topic_id
            );
            usleep(1500);
        }
    }

    // редактирование сообщений
    foreach ($posts_ids as $index => $post_id) {
        $post_number = $index + 1;
        Log::append("Редактирование сообщения № $post_number...");
        $message = empty($tmp['msg'][$index]) ? 'резерв' : $tmp['msg'][$index];
        $reports->send_message(
            'editpost',
            $message,
            $topic_id,
            $post_id
        );
    }
    unset($posts_ids);

    // работа с шапкой
    if ($cfg['tracker_login'] == $author_nickname) {
        $tmp['header'] = '[url=viewforum.php?f=' . $forum_id . '][u][color=#006699]' . preg_replace('/.*» ?(.*)$/', '$1', $forum[$forum_id]['na']) . '[/u][/color][/url] ' .
        '| [url=tracker.php?f=' . $forum_id . '&tm=-1&o=10&s=1&oop=1][color=indigo][u]Проверка сидов[/u][/color][/url][br][br]' .
        'Актуально на: [color=darkblue]' . date('d.m.Y', $update_time[0]) . '[/color][br]' .
        'Всего раздач в подразделе: ' . $forum[$forum_id]['qt'] . ' шт. / ' . convert_bytes($forum[$forum_id]['si']) . '[br]' .
        'Всего хранимых раздач в подразделе: %%dlqt%% шт. / %%dlsi%%[br]' .
        'Количество хранителей: %%kpqt%%[hr]' .
        'Хранитель 1: [url=profile.php?mode=viewprofile&u=' . urlencode($cfg['tracker_login']) . '&name=1][u][color=#006699]' . $cfg['tracker_login'] . '[/u][/color][/url] [color=gray]~>[/color] ' . $tmp['dlqt'] . ' шт. [color=gray]~>[/color] ' . convert_bytes($tmp['dlsi']) . '[br]';
        // значения хранимого для шапки
        $count_keepers = 1;
        $sumdlqt_keepers = $tmp['dlqt'];
        $sumdlsi_keepers = $tmp['dlsi'];
        // учитываем хранимое другими
        if (isset($stored)) {
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
            }
        }
        unset($stored);
        // вставляем общее хранимое в шапку
        $tmp['header'] = str_replace(
            array(
                '%%dlqt%%',
                '%%dlsi%%',
                '%%kpqt%%',
            ),
            array(
                $sumdlqt_keepers,
                convert_bytes($sumdlsi_keepers),
                $count_keepers,
            ),
            $tmp['header']
        );
        Log::append('Отправка шапки...');
        // отправка сообщения с шапкой
        $reports->send_message(
            'editpost',
            $tmp['header'],
            $topic_id,
            $author_post_id,
            '[Список] ' . $subsection['na']
        );
    }

    // добавляем информацию в сводный отчёт
    $sumdlqt += $tmp['dlqt'];
    $sumdlsi += $tmp['dlsi'];
    $common_forums[$forum_id] = sprintf(
        $pattern_common,
        $topic_id,
        $forum[$forum_id]['na'],
        $tmp['dlqt'],
        convert_bytes($tmp['dlsi'])
    );

    unset($tmp);

}

// работаем со сводным отчётом
$common_exclude = TIniFileEx::read('reports', 'common', 1);

if ($common_exclude) {
    // формируем сводный отчёт
    $common = 'Актуально на: [b]' . date('d.m.Y', $update_time[0]) . '[/b][br][br]' .
    'Общее количество хранимых раздач: [b]' . $sumdlqt . '[/b] шт.[br]' .
    'Общий вес хранимых раздач: [b]' . preg_replace('/ (?!.* )/', '[/b] ', convert_bytes($sumdlsi)) . '[hr]' .
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

$endtime = microtime(true);

Log::append("Отправка отчётов завершена за " . convert_seconds($endtime - $starttime));
