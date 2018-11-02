<?php

try {

    include_once dirname(__FILE__) . '/../common.php';
    include_once dirname(__FILE__) . '/../classes/reports.php';

    // идентификатор подраздела
    if (isset($_POST['forum_id'])) {
        $forum_id = (int) $_POST['forum_id'];
    }

    if (
        !is_int($forum_id)
        || $forum_id < 0
    ) {
        throw new Exception("Error: Неправильный идентификатор подраздела ($forum_id)");
    }

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

    // подключаемся к форуму
    $reports = new Reports(
        $cfg['forum_url'],
        $cfg['tracker_login'],
        $cfg['tracker_paswd']
    );

    if ($forum_id === 0) {

        // сводный отчёт
        $sumdlqt = 0;
        $sumdlsi = 0;
        $pattern_common = '[url=viewtopic.php?t=%s][u]%s[/u][/url] — %s шт. (%s)';

        // идентификаторы хранимых подразделов
        $forums_ids = array_keys($cfg['subsections']);
        $in = str_repeat('?,', count($forums_ids) - 1) . '?';

        // вытаскиваем из базы хранимое
        $stored = Db::query_database(
            "SELECT ss,COUNT(),SUM(si) FROM Topics
			LEFT JOIN Clients ON Topics.hs = Clients.hs
			WHERE dl IN (1,-1) AND ss IN ($in) GROUP BY ss",
            $forums_ids,
            true,
            PDO::FETCH_NUM | PDO::FETCH_UNIQUE
        );

        if (empty($stored)) {
            throw new Exception("Error: Не получены данные о хранимых раздачах");
        }

        // разбираем хранимое
        foreach ($stored as $forum_id => $values) {
            $title = $cfg['subsections'][$forum_id]['na'];
            // ищем тему со списками
            $topic_id = $reports->search_topic_id($title);
            $topic_id = empty($topic_id) ? 'NaN' : $topic_id;
            // инфа о подразделе в сводный
            $common_forums[] = sprintf(
                $pattern_common,
                $topic_id,
                $title,
                $values[0],
                convert_bytes($values[1])
            );
            // находим общее хранимое
            $sumdlqt += $values[0];
            $sumdlsi += $values[1];
        }

        // формируем сводный отчёт
        $output = 'Актуально на: [b]' . date('d.m.Y', $update_time[0]) . '[/b]<br />[br]<br />' .
        'Общее количество хранимых раздач: [b]' . $sumdlqt . '[/b] шт.<br />' .
        'Общий вес хранимых раздач: [b]' . preg_replace('/ (?!.* )/', '[/b] ', convert_bytes($sumdlsi)) . '<br />[hr]<br />' .
        implode('<br />', $common_forums);

    } else {

        // хранимые подразделы

        // const & pattern
        $message_length_max = 119000;
        $pattern_topic = '[url=viewtopic.php?t=%s]%s[/url] %s';
        $pattern_spoiler = '[spoiler="№№ %s — %s"][list=1]<br />[*=%s]%s<br />[/list]<br />[/spoiler]';
        $pattern_header = 'Хранитель %s: [url=profile.php?mode=viewprofile&u=%s&name=1][u][color=#006699]%s[/u][/color][/url] [color=gray]~>[/color] %s шт. [color=gray]~>[/color] %s<br />';
        $spoiler_length = mb_strlen($pattern_spoiler, 'UTF-8');

        // получение данных о подразделе
        $forum = Db::query_database(
            "SELECT * FROM Forums WHERE id = ?",
            array($forum_id),
            true,
            PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE
        );

        if (empty($forum)) {
            throw new Exception("Error: Не получены данные о хранимом подразделе № $forum_id");
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
            throw new Exception("Error: Не получены данные о хранимых раздачах для подраздела № $forum_id");
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
            $available_length = $message_length_max - $spoiler_length - ($tmp['dlqt'] - $tmp['start'] + 1) * 4;
            if (
                $current_length > $available_length
                || $tmp['dlqt'] == $topics_count
            ) {
                $tmp['str'] = implode('<br />[*]', $tmp['str']);
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
            throw new Exception("Error: Не удалось сформировать список хранимого для подраздела № $forum_id");
        }

        // дописываем в начало первого сообщения
        $tmp['msg'][0] = 'Актуально на: [color=darkblue]' . date('d.m.Y', $update_time[0]) . '[/color]<br />' .
        'Всего хранимых раздач в подразделе: ' . $tmp['dlqt'] . ' шт. / ' . convert_bytes($tmp['dlsi']) .
            '<br />' . $tmp['msg'][0];

        // собираем сообщения
        array_walk($tmp['msg'], function (&$a, $b) {
            $b++;
            $a = "<h3>Сообщение $b</h3><div title=\"Выполните двойной клик для выделения всего сообщения\">$a</div>";
        });
        $tmp['msg'] = '<div class="report_message">' . implode('', $tmp['msg']) . '</div>';

        // ищем тему со списками
        $topic_id = $reports->search_topic_id($forum[$forum_id]['na']);

        $topic_id = empty($topic_id) ? 'NaN' : $topic_id;

        // Log::append("Сканирование списков...");

        // сканируем имеющиеся списки
        $keepers = $reports->scanning_viewtopic($topic_id);

        if (empty($keepers)) {
            throw new Exception("Error: Не удалось просканировать списки для подраздела № $forum_id");
        }

        // разбираем инфу, полученную из списков
        foreach ($keepers as $index => $keeper) {
            // array( 'post_id' => 4444444, 'nickname' => 'user', 'topics_ids' => array( 0,1,2 ) )
            if ($keeper['nickname'] == $cfg['tracker_login']) {
                continue;
            }
            // считаем сообщения других хранителей в подразделе
            if (!empty($keeper['topics_ids'])) {
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

        $tmp['header'] = '[url=viewforum.php?f=' . $forum_id . '][u][color=#006699]' . preg_replace('/.*» ?(.*)$/', '$1', $forum[$forum_id]['na']) . '[/u][/color][/url] ' .
        '| [url=tracker.php?f=' . $forum_id . '&tm=-1&o=10&s=1&oop=1][color=indigo][u]Проверка сидов[/u][/color][/url]<br />[br]<br />' .
        'Актуально на: [color=darkblue]' . date('d.m.Y', $update_time[0]) . '[/color]<br />' .
        'Всего раздач в подразделе: ' . $forum[$forum_id]['qt'] . ' шт. / ' . convert_bytes($forum[$forum_id]['si']) . '<br />' .
        'Всего хранимых раздач в подразделе: %%dlqt%% шт. / %%dlsi%%<br />' .
        'Количество хранителей: %%kpqt%%<br />[hr]<br />' .
        'Хранитель 1: [url=profile.php?mode=viewprofile&u=' . urlencode($cfg['tracker_login']) . '&name=1][u][color=#006699]' . $cfg['tracker_login'] . '[/u][/color][/url] [color=gray]~>[/color] ' . $tmp['dlqt'] . ' шт. [color=gray]~>[/color] ' . convert_bytes($tmp['dlsi']) . '<br />';

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
        ) . '<br />';

        $output = $tmp['header'] . $tmp['msg'];

        unset($tmp);

    }

    echo json_encode(array(
        'report' => $output,
        'log' => Log::get(),
    ));

} catch (Exception $e) {

    Log::append($e->getMessage());
    echo json_encode(array(
        'log' => Log::get(),
        'report' => "<br /><div>Нет или недостаточно данных для отображения.<br />Проверьте настройки и выполните обновление сведений.</div><br />",
    ));

}
