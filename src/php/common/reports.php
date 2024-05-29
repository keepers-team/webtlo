<?php

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Config\Validate as ConfigValidate;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Forum\Report\Creator as ReportCreator;
use KeepersTeam\Webtlo\Forum\SendReport;
use KeepersTeam\Webtlo\Legacy\Db;
use KeepersTeam\Webtlo\Module\Forums;
use KeepersTeam\Webtlo\Module\LastUpdate;
use KeepersTeam\Webtlo\Timers;

include_once dirname(__FILE__) . '/../../vendor/autoload.php';
include_once dirname(__FILE__) . '/../classes/reports.php';
include_once dirname(__FILE__) . '/../classes/user_details.php';

$app = AppContainer::create('reports.log');
$log = $app->getLogger();

Timers::start('send_reports');
$log->info('Начат процесс отправки отчётов...');

// Получение настроек.
$cfg = $app->getLegacyConfig();

if (isset($checkEnabledCronAction)) {
    $checkEnabledCronAction = $cfg['automation'][$checkEnabledCronAction] ?? -1;
    if ($checkEnabledCronAction == 0) {
        throw new Exception('Notice: Автоматическая отправка отчётов отключена в настройках.');
    }
}

// Проверка настроек.
$user = ConfigValidate::checkUser($cfg);
if (empty($cfg['subsections'])) {
    $log->error('Не выбраны хранимые подразделы');

    return;
}

// Проверим полное обновление.
LastUpdate::checkReportsSendAvailable($cfg);

Timers::start('init_report');

// Желание отправить отчёты на форум.
$sendForumReports = (bool)($cfg['reports']['send_report_forum'] ?? true);

// Желание отправить сводный отчёт на форум.
$sendForumSummary = (bool)($cfg['reports']['send_summary_report'] ?? true);

// Проверим заполненность таблиц.
if ($sendForumReports && Db::select_count('ForumsOptions') === 0) {
    $log->error(
        'Отправка отчётов невозможна. Отсутствуют сведения о сканировании подразделов. Выполните полное обновление сведений.'
    );
    $sendForumReports = false;
}

// Возможность отправить любые отчёты на форум.
$forumReportAvailable = $sendForumReports || $sendForumSummary;

if ($forumReportAvailable) {
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
        $log->error($unavailable->value);
        $forumReportAvailable = false;
    }
}
$log->debug(sprintf('init report %s', Timers::getExecTime('init_report')));

Timers::start('create_report');
// Создание отчётов.
$forumReports = new ReportCreator(
    $cfg,
    $user
);
$forumReports->initConfig();
if ($forumReportAvailable) {
    $forumReports->fillStoredValues();
}

$log->debug(sprintf('create report %s', Timers::getExecTime('create_report')));

Timers::start('create_api');

/** @var SendReport $sendReport */
$sendReport = $app->get(SendReport::class);

// Желание отправить отчёт через API.
$sendReport->setEnable((bool)($cfg['reports']['send_report_api'] ?? true));

// Проверяем доступность API.
if ($sendReport->isEnable()) {
    $sendReport->checkAccess();

    $log->debug(sprintf('create api %s', Timers::getExecTime('create_api')));
}

if (!$sendReport->isEnable()) {
    $log->info('Отправка отчёта в API невозможна или отключена');
}

$forumReportCount = 0;
$apiReportCount   = 0;

$editedTopicsIDs = [];
$editedPosts     = [];

$Timers = [];

$forumCount = count($forumReports->forums);
foreach ($forumReports->forums as $forum_id) {
    $timer = [];

    // Пробуем отправить отчёт по API.
    if ($sendReport->isEnable() && !$forumReports->isForumExcluded($forum_id)) {
        Timers::start("send_api_$forum_id");
        try {
            Timers::start("search_db_$forum_id");

            // Получаем раздачи, которые нужно отправить.
            $topicsToReport = $forumReports->getStoredForumTopics($forum_id);

            $timer['search_db'] = Timers::getExecTime("search_db_$forum_id");

            // Пробуем отправить отчёт по API.
            $apiResult = $sendReport->sendForumTopics((int)$forum_id, $topicsToReport);

            $apiReportCount++;
            $timer['send_api'] = Timers::getExecTime("send_api_$forum_id");

            $log->debug(
                sprintf("API. Отчёт отправлен [%d/%d] %s", $apiReportCount, $forumCount, $timer['send_api']),
                $apiResult
            );
        } catch (Exception $e) {
            $log->notice(
                sprintf(
                    'Попытка отправки отчёта через API для подраздела %d не удалась. Причина %s',
                    $forum_id,
                    $e->getMessage()
                )
            );
        }
    }

    // Пробуем отправить отчёт на форум.
    if ($forumReportAvailable && $sendForumReports && isset($reports)) {
        Timers::start("report_forum_$forum_id");
        try {
            $forum = Forums::getForum($forum_id);
            if (null === $forum->topic_id) {
                $log->notice(
                    sprintf(
                        'Отсутствует номер темы со списками для подраздела %d. Выполните обновление сведений.',
                        $forum_id
                    )
                );
                continue;
            }

            Timers::start("create_$forum_id");
            $forumReport = $forumReports->getForumReport($forum);
        } catch (Exception $e) {
            $log->warning(
                sprintf(
                    'Формирование отчёта для подраздела %d пропущено. Причина %s',
                    $forum_id,
                    $e->getMessage()
                )
            );
            continue;
        }

        $createTime = Timers::getExecTime("create_$forum_id");

        Timers::start("send_$forum_id");
        $topicId = $forum->topic_id;
        $messages = $forumReport['messages'];// Редактируем шапку темы, если её автор - пользователь.
        if ($user->userId === $forum->author_id && $forum->author_post_id && !empty($forumReport['header'])) {
            $log->info(sprintf('Отправка шапки, ид темы %d, ид сообщения %d', $topicId, $forum->author_post_id));
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

        // Вставка дополнительных сообщений в тему.
        $postList = $forum->post_ids ?? [];
        if (count($messages) > count($postList)) {
            $count_post_reply = count($messages) - count($postList);
            for ($i = 1; $i <= $count_post_reply; $i++) {
                $message = '[spoiler]' . $i . str_repeat('?', 119981 - mb_strlen((string)$i)) . '[/spoiler]';
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
            $message     = $messages[$index] ?? 'резерв';
            $reports->send_message(
                'editpost',
                $message,
                $topicId,
                $postId
            );
            $editedPosts[$topicId][] = $postId;

            unset($index, $postId, $post_number, $message);
        }

        $editedTopicsIDs[] = $topicId;

        $timer['send_forum'] = Timers::getExecTime("send_forum_$forum_id");

        $log->debug(
            sprintf('Forum. Отчёт отправлен [%d/%d] %s', $forumReportCount++, $forumCount, $timer['send_forum'])
        );
    }

    $forumReports->clearCache($forum_id);
    $Timers[] = ['forum' => $forum_id, ...$timer];
}

if (!empty($Timers)) {
    $log->debug(json_encode($Timers));
}

if ($apiReportCount > 0) {
    $log->info(sprintf('Отчётов отправлено в API: %d шт.', $apiReportCount));
}

if (count($editedTopicsIDs)) {
    $log->info(sprintf('Отчётов отправлено на форум: %d шт.', count($editedTopicsIDs)));
    $log->debug(json_encode($editedPosts));
}


if ($forumReportAvailable && isset($reports)) {
    // Отправим сводный отчёт, даже если отправка обычных отчётов на форум отключена.
    if ($sendForumSummary) {
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
        LastUpdate::setTime(UpdateMark::SEND_REPORT->value);

        $log->info(sprintf('Отправка сводного отчёта завершена за %s', Timers::getExecTime('send_summary')));
    }

    // Если ни одного отчёта по разделу не отправлено на форум, завершаем работу.
    if (!count($editedTopicsIDs)) {
        return;
    }

    // отредактируем все сторонние темы со своими сообщениями в рабочем подфоруме
    if ($cfg['reports']['auto_clear_messages']) {
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
            $log->info(
                sprintf(
                    'Помечено неактуальных сообщений: %d => %s',
                    count($emptyMessages),
                    implode(',', $emptyMessages)
                )
            );
        }
    }
}

$log->info(sprintf('Процесс отправки отчётов завершён за %s', Timers::getExecTime('send_reports')));
