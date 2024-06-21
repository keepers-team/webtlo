<?php

declare(strict_types=1);

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Config\Validate as ConfigValidate;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Forum\Report\Creator as ReportCreator;
use KeepersTeam\Webtlo\Forum\SendReport;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Tables\UpdateTime;
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
    if (!Helper::isScheduleActionEnabled($cfg, $checkEnabledCronAction)) {
        $log->notice('Автоматическая отправка отчётов отключена в настройках.');

        return;
    }
}

// Проверка настроек.
$user = ConfigValidate::checkUser($cfg);
if (empty($cfg['subsections'])) {
    $log->error('Не выбраны хранимые подразделы');

    return;
}

Timers::start('init_api_report');
// Подключаемся к API отчётов.
/** @var SendReport $sendReport */
$sendReport = $app->get(SendReport::class);

// Желание отправить отчёт через API.
$sendReport->setEnable((bool)($cfg['reports']['send_report_api'] ?? true));

// Проверяем доступность API.
if ($sendReport->isEnable()) {
    $sendReport->checkAccess();
}

if (!$sendReport->isEnable()) {
    $log->notice('Отправка отчёта в API невозможна или отключена');
}
$log->debug('init api report {sec}', ['sec' => Timers::getExecTime('init_api_report')]);


Timers::start('create_report');

/** @var ReportCreator $forumReports Создание отчётов */
$forumReports = $app->get(ReportCreator::class);
$forumReports->initConfig($cfg);

$log->debug('create report {sec}', ['sec' => Timers::getExecTime('create_report')]);


/** @var UpdateTime $updateTime */
$updateTime = $app->get(UpdateTime::class);

// Проверим полное обновление.
if (!$updateTime->checkReportsSendAvailable($forumReports->forums, $log)) {
    return;
};


// Если API доступно - отправляем отчёты.
if ($sendReport->isEnable()) {
    $Timers = [];

    $forumCount = $forumReports->getForumCount();

    $apiReportCount = 0;
    $forumsToReport = [];
    foreach ($forumReports->forums as $forum_id) {
        // Пропускаем исключённые подразделы.
        if ($forumReports->isForumExcluded($forum_id)) {
            continue;
        }

        $timer = [];

        // Пробуем отправить отчёт по API.
        $forumsToReport[] = $forum_id;

        Timers::start("send_api_$forum_id");
        try {
            Timers::start("search_db_$forum_id");

            // Получаем раздачи, которые нужно отправить.
            $topicsToReport = $forumReports->getStoredForumTopics($forum_id);

            $timer['search_db'] = Timers::getExecTime("search_db_$forum_id");

            // Пробуем отправить отчёт по API.
            $apiResult = $sendReport->sendForumTopics((int)$forum_id, $topicsToReport);

            $timer['send_api'] = Timers::getExecTime("send_api_$forum_id");

            $log->debug(
                'API. Отчёт отправлен [{current}/{total}] {sec}',
                ['current' => ++$apiReportCount, 'total' => $forumCount, 'sec' => $timer['send_api'], ...$apiResult]
            );

            unset($topicsToReport, $apiResult);
        } catch (Exception $e) {
            $log->notice(
                'Попытка отправки отчёта через API для подраздела {forum_id} не удалась. Причина {error}',
                ['forum_id' => $forum_id, 'error' => $e->getMessage()]
            );
        }

        $forumReports->clearCache($forum_id);
        $Timers[] = ['forum' => $forum_id, ...$timer];

        unset($forum_id, $timer);
    }


    // Отправка статуса хранимых подразделов и снятие галки с не хранимых.
    if (count($forumsToReport)) {
        $unsetOtherForums = (bool)($cfg['reports']['unset_other_forums'] ?? true);

        $setStatus = $sendReport->setForumsStatus($forumsToReport, $unsetOtherForums);
        $log->debug('kept forums setStatus', $setStatus);
    }


    // Запишем таймеры в журнал.
    if (!empty($Timers)) {
        $log->debug(json_encode($Timers));
    }

    if ($apiReportCount > 0) {
        $log->info('Отчётов отправлено в API: {count} шт.', ['count' => $apiReportCount]);

        // Запишем время отправки отчётов.
        $updateTime->setMarkerTime(UpdateMark::SEND_REPORT->value);
    }
}


// Желание отправить сводный отчёт на форум.
$sendSummaryReport = (bool)($cfg['reports']['send_summary_report'] ?? true);
if ($sendSummaryReport) {
    // Подключаемся к форуму.
    try {
        $reports = new Reports(
            $cfg['forum_address'],
            $user,
        );

        // Применяем таймауты.
        $reports->curl_setopts($cfg['curl_setopt']['forum']);

        // Проверяем возможность работы с форумом.
        if ($unavailable = $reports->check_access()) {
            throw new Exception($unavailable->value);
        }

        Timers::start('send_summary');
        // Формируем сводный отчёт.
        $summaryReport = $forumReports->getSummaryReport();

        // Ищем своё сообщение со сводным отчётом (если есть).
        $summaryPostId = $reports->search_post_id(ReportCreator::SUMMARY_FORUM, true);

        $summaryPostMode = empty($summaryPostId) ? 'reply' : 'editpost';
        // Отправляем сводный отчёт.
        $reports->send_message(
            $summaryPostMode,
            $summaryReport,
            ReportCreator::SUMMARY_FORUM,
            $summaryPostId
        );

        // Запишем время отправки отчётов.
        $updateTime->setMarkerTime(UpdateMark::SEND_REPORT->value);

        $log->info('Отправка сводного отчёта завершена за {sec}', ['sec' => Timers::getExecTime('send_summary')]);
    } catch (Exception $e) {
        $log->error($e->getMessage());
        $log->warning('Нет доступа к форуму. Отправка сводного отчёта невозможна.');
    }
} else {
    $log->notice('Отправка сводного отчёта на форум отключена в настройках.');
}

$log->info('Процесс отправки отчётов завершён за {sec}', ['sec' => Timers::getExecTime('send_reports')]);
