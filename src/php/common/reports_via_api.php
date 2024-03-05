<?php

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Config\Validate as ConfigValidate;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Forum\Report\Creator as ReportCreator;
use KeepersTeam\Webtlo\Legacy\Db;
use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\Module\Forums;
use KeepersTeam\Webtlo\Module\LastUpdate;
use KeepersTeam\Webtlo\Timers;
use KeepersTeam\Webtlo\External\ApiReportClient;

include_once dirname(__FILE__) . '/../../vendor/autoload.php';
include_once dirname(__FILE__) . '/../classes/reports.php';
include_once dirname(__FILE__) . '/../classes/user_details.php';

App::init();

Timers::start('send_reports');
Log::append('Начат процесс отправки отчётов через API...');

// Получение настроек.
if (!isset($cfg)) {
    $cfg = App::getSettings();
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

$apiReportClient = new ApiReportClient($cfg);

$editedTopicsIDs = [];
$Timers = [];
foreach ($forumReports->forums as $forum_id) {
    Timers::start("create_$forum_id");
    $done_topic_ids        = [];
    $downloading_topic_ids = [];
    foreach ($forumReports->getStoredForumTopics($forum_id) as $stored_topic) {
        if ($stored_topic['done'] != 1) {
            $downloading_topic_ids[] = $stored_topic['id'];
        } else {
            $done_topic_ids[] = $stored_topic['id'];
        }
    }
    $createTime  = Timers::getExecTime("create_$forum_id");

    Timers::start("send_$forum_id");

    $response = $apiReportClient->report_releases(
        $forum_id, $done_topic_ids, $apiReportClient->KEEPING_STATUSES['reported_by_api'], true);
    Log::append("Reporting seeding: {$response}");

    if (count($downloading_topic_ids)) {
        $response = $apiReportClient->report_releases(
            $forum_id,
            $downloading_topic_ids,
            $apiReportClient->KEEPING_STATUSES['reported_by_api'] | $apiReportClient->KEEPING_STATUSES['downloading'],
            false,
        );
        Log::append("Reporting downloading: {$response}");
    }

    $Timers[] = [
        'forum'    => $forum_id,
        'create'   => $createTime,
        'send'     => Timers::getExecTime("send_$forum_id"),
    ];
}

Log::append("Обработано подразделов: " . count($editedTopicsIDs) . " шт.");
Log::append(json_encode($Timers));

Log::append(sprintf('Процесс отправки отчётов завершён за %s', Timers::getExecTime('send_reports')));
