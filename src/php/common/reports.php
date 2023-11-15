<?php

use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Config\Validate as ConfigValidate;
use KeepersTeam\Webtlo\Forum\Report\Creator as ReportCreator;
use KeepersTeam\Webtlo\Module\Forums;
use KeepersTeam\Webtlo\Module\LastUpdate;

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/reports.php';
include_once dirname(__FILE__) . '/../classes/user_details.php';

Timers::start('send_reports');
Log::append('Начат процесс отправки отчётов...');

// Получение настроек.
if (!isset($cfg)) {
    $cfg = get_settings();
}

if (isset($checkEnabledCronAction)) {
    $checkEnabledCronAction = $cfg['automation'][$checkEnabledCronAction] ?? -1;
    if ($checkEnabledCronAction == 0) {
        throw new Exception('Notice: Автоматическая отправка отчётов отключена в настройках.');
    }
}

// Проверка настроек.
$user = ConfigValidate::checkUser($cfg);
if (empty($cfg['subsections'])) {
    throw new Exception('Error: Не выбраны хранимые подразделы');
}

// Проверим полное обновление.
LastUpdate::checkReportsSendAvailable($cfg);

// Проверим заполненность таблиц.
if (Db::select_count('ForumsOptions') === 0) {
    throw new Exception('Error: Отправка отчётов невозможна. Отсутствуют сведеения о сканировании подразделов. Выполните полное обновление сведений.');
}

// Подключаемся к форуму.
if (!isset($reports)) {
    $reports = new Reports(
        $cfg['forum_address'],
        $user,
    );
    // применяем таймауты
    $reports->curl_setopts($cfg['curl_setopt']['forum']);
}

if ($unavailable = $reports->check_access()) {
    throw new Exception($unavailable->value);
}

// Создание отчётов.
$forumReports = new ReportCreator(
    $cfg,
    $user
);
$forumReports->initConfig();
$forumReports->fillStoredValues();

$editedTopicsIDs = [];
$Timers = [];
foreach ($forumReports->forums as $forum_id) {
    $forum = Forums::getForum($forum_id);
    // Log::append(sprintf('forum_id: %d => %s', $forum_id, json_encode($forum, JSON_UNESCAPED_UNICODE)));
    if (null === $forum->topic_id) {
        Log::append(sprintf('Notice: Отсутствует номер темы со списками для подраздела %d. Выполните обновление сведений.', $forum_id));
        continue;
    }

    Timers::start("create_$forum_id");
    try {
        $forumReport = $forumReports->getForumReport($forum);
    } catch (Exception $e) {
        Log::append(sprintf(
            'Notice: Формирование отчёта для подраздела %d пропущено. Причина %s',
            $forum_id,
            $e->getMessage()
        ));
        continue;
    }
    $createTime  = Timers::getExecTime("create_$forum_id");

    Timers::start("send_$forum_id");

    $topicId  = $forum->topic_id;
    $messages = $forumReport['messages'];
    // Редактируем шапку темы, если её автор - пользователь.
    if ($user->userId === $forum->author_id && $forum->author_post_id && !empty($forumReport['header'])) {
        Log::append(sprintf('Отправка шапки, ид темы %d, ид сообщения %d', $topicId, $forum->author_post_id));
        // отправка сообщения с шапкой
        $reports->send_message(
            'editpost',
            $forumReport['header'],
            $topicId,
            $forum->author_post_id,
            '[Список] ' . $forum->name
        );
        usleep(500);
    }

    // вставка доп. сообщений
    $postList = $forum->post_ids ?? [];
    if (count($messages) > count($postList)) {
        $count_post_reply = count($messages) - count($postList);
        for ($i = 1; $i <= $count_post_reply; $i++) {
            // Log::append("Вставка дополнительного $i-ого сообщения...");
            $message = '[spoiler]' . $i . str_repeat('?', 119981 - mb_strlen($i)) . '[/spoiler]';
            $post_id = $reports->send_message(
                'reply',
                $message,
                $topicId
            );
            if ($post_id > 0) {
                $postList[] = (int)$post_id;
            }
            usleep(500);

            unset($message, $post_id);
        }
        if ($count_post_reply > 0) {
            Forums::updatePostList($forum_id, $postList);
        }
        unset($count_post_reply);
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

    $Timers[] = [
        'forum'    => $forum_id,
        'create'   => $createTime,
        'send'     => Timers::getExecTime("send_$forum_id"),
        'messages' => count($postList),
    ];
}

// Если ни одного отчёта по разделу не отправлено, то тормозим выполнение.
if (!count($editedTopicsIDs)) {
    Log::append('Не удалось отправить отчёты, см. журнал выше.');
    return;
}

Log::append("Обработано подразделов: " . count($editedTopicsIDs) . " шт.");
Log::append(json_encode($Timers));

// работаем со сводным отчётом
if ($cfg['reports']['send_summary_report']) {
    Timers::start('send_summary');
    // формируем сводный отчёт
    $summaryReport = $forumReports->getSummaryReport();

    // ищем сообщение со сводным
    $summaryPostId = $reports->search_post_id(ReportCreator::SUMMARY_FORUM, true);

    $summaryPostMode = empty($summaryPostId) ? 'reply' : 'editpost';
    // отправляем сводный отчёт
    $reports->send_message(
        $summaryPostMode,
        $summaryReport,
        ReportCreator::SUMMARY_FORUM,
        $summaryPostId
    );

    // Запишем ид темы со сводными, чтобы очистка сообщений не задела.
    $editedTopicsIDs[] = ReportCreator::SUMMARY_FORUM;

    // Запишем время отправки отчётов.
    LastUpdate::setTime(UpdateMark::FULL_UPDATE->value);

    Log::append(sprintf('Отправка сводного отчёта завершена за %s', Timers::getExecTime('send_summary')));
}

// отредактируем все сторонние темы со своими сообщениями в рабочем подфоруме
if (
    $cfg['reports']['auto_clear_messages']
    && !empty($editedTopicsIDs)
) {
    $emptyMessages = [];
    $topicsIDsWithMyMessages = $reports->searchTopicsIDs(['uid' => $user->userId]);
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
                if ($user->userId === $message['user_id']) {
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
