<?php

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/reports.php';
include_once dirname(__FILE__) . '/../classes/ReportCreator.php';
include_once dirname(__FILE__) . '/../classes/user_details.php';

Timers::start('send_reports');
Log::append('Начат процесс отправки отчётов...');

// получение настроек
$cfg = get_settings();

// проверка настроек
if (empty($cfg['subsections'])) {
    throw new Exception('Error: Не выбраны хранимые подразделы');
}

if (empty($cfg['tracker_login'])) {
    throw new Exception('Error: Не указано имя пользователя для доступа к форуму');
}

if (empty($cfg['tracker_paswd'])) {
    throw new Exception('Error: Не указан пароль пользователя для доступа к форуму');
}

// подключаемся к форуму
$reports = new Reports(
    $cfg['forum_address'],
    $cfg['tracker_login'],
    $cfg['tracker_paswd']
);
// применяем таймауты
$reports->curl_setopts($cfg['curl_setopt']['forum']);

// Создание отчётов.
$forumReports = new ReportCreator(
    $cfg,
    $webtlo,
    $reports
);

$editedTopicsIDs = [];
foreach ($cfg['subsections'] as $forum_id => $subsection) {
    Timers::start("send_$forum_id");

    $forumReport = $forumReports->getForumReport($forum_id);

    $messages = $forumReport['messages'];
    $topicParams = $forumReports->getTopicSavedParams($forum_id);
    // Log::append(sprintf('forum_id: %d => %s', $forum_id, json_encode($topicParams, JSON_UNESCAPED_UNICODE)));

    $topicId = $topicParams['topicId'];
    // Редактируем шапку темы, если её автор - пользователь.
    if (strcasecmp($cfg['tracker_login'], $topicParams['authorNickName']) === 0) {
        Log::append(sprintf('Отправка шапки, ид темы %d, ид сообщения %d', $topicId, $topicParams['authorPostId']));
        // отправка сообщения с шапкой
        $reports->send_message(
            'editpost',
            $forumReport['header'],
            $topicId,
            $topicParams['authorPostId'],
            '[Список] ' . $subsection['na']
        );
        usleep(500);
    }

    // вставка доп. сообщений
    $postList = $topicParams['postList'];
    if (count($messages) > count($postList)) {
        $count_post_reply = count($messages) - count($postList);
        for ($i = 1; $i <= $count_post_reply; $i++) {
            // Log::append("Вставка дополнительного $i-ого сообщения...");
            $message = '[spoiler]' . $i . str_repeat('?', 119981 - mb_strlen($i)) . '[/spoiler]';
            $postList[] = $reports->send_message(
                'reply',
                $message,
                $topicId
            );
            usleep(500);
        }
    }

    // редактирование сообщений
    foreach ($postList as $index => $postId) {
        $post_number = $index + 1;
        // Log::append("Редактирование сообщения № $post_number...");
        $message = $messages[$index] ?? 'резерв';
        $reports->send_message(
            'editpost',
            $message,
            $topicId,
            $postId
        );

        unset($index, $postId, $post_number, $message);
    }

    $editedTopicsIDs[] = $topicId;
    Log::append(sprintf(
        'Отправка отчёта для подраздела № %d завершена за %s. Сообщений отредактировано %d.',
        $forum_id,
        Timers::getExecTime("send_$forum_id"),
        count($postList)
    ));
}

Log::append("Обработано подразделов: " . count($editedTopicsIDs) . " шт.");

// работаем со сводным отчётом
if ($cfg['reports']['send_summary_report']) {
    Timers::start('send_summary');
    // формируем сводный отчёт
    $summaryReport = $forumReports->getSummaryReport();

    // ищем сообщение со сводным
    $summaryPostId = $reports->search_post_id(4275633, true);

    $summaryPostMode = empty($summaryPostId) ? 'reply' : 'editpost';
    // отправляем сводный отчёт
    $reports->send_message(
        $summaryPostMode,
        $summaryReport,
        4275633,
        $summaryPostId
    );
    Log::append(sprintf('Отправка сводного отчёта завершена за %s', Timers::getExecTime('send_summary')));
}

// отредактируем все сторонние темы со своими сообщениями в рабочем подфоруме
if (
    $cfg['reports']['auto_clear_messages']
    && !empty($editedTopicsIDs)
) {
    $emptyMessages = [];
    $topicsIDsWithMyMessages = $reports->searchTopicsIDs(['uid' => $cfg['user_id']]);
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
                    $emptyMessages[] = $message['post_id'];
                    $reports->send_message('editpost', ':!: не актуально', $topicID, $message['post_id']);
                }
            }
        }
    }

    if (count($emptyMessages)) {
        Log::append(sprintf(
            'Помечено неактуальных сообщений: %d => %s',
            count($emptyMessages),
            implode(',', $emptyMessages)
        ));
    }
}

Log::append(sprintf('Процесс отправки отчётов завершён за %s', Timers::getExecTime('send_reports')));
