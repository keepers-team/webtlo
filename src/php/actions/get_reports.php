<?php

use KeepersTeam\Webtlo\Module\Forums;
use KeepersTeam\Webtlo\Module\ReportCreator;

$reports_result = [
    'report' => '',
];
try {
    include_once dirname(__FILE__) . '/../common.php';
    include_once dirname(__FILE__) . '/../classes/reports.php';

    // идентификатор подраздела
    $forum_id = (int)$_POST['forum_id'] ?? -1;

    if ($forum_id < 0) {
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

    // Подключаемся к форуму.
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
        get_webtlo_version()
    );
    $forumReports->setMode('UI');

    $Timers = [];
    Timers::start('create_report');
    if ($forum_id === 0) {
        // Сводный отчёт
        $output = $forumReports->getSummaryReport();
    } else {
        // Хранимые подразделы
        $forum = Forums::getForum($forum_id);
        Timers::start("create_$forum_id");
        try {
            $forumReport = $forumReports->getForumReport($forum);
        } catch (Exception $e) {
            throw new Exception(sprintf(
                'Notice: Формирование отчёта для подраздела %d прекращено. Причина %s',
                $forum_id,
                $e->getMessage()
            ));
        }

        $output = $forumReports->prepareReportsMessages($forumReport);
    }

    Log::append(json_encode([
        'forum'    => $forum_id,
        'create'   => Timers::getExecTime('create_report'),
    ]));

    $reports_result['report'] = $output;
} catch (Exception $e) {
    Log::append($e->getMessage());
    $reports_result['report'] = "<br /><div>Нет или недостаточно данных для отображения.<br />Проверьте настройки, журнал и выполните обновление сведений.</div><br />";
}

// выводим лог
$reports_result['log'] = Log::get();

echo json_encode($reports_result, JSON_UNESCAPED_UNICODE);
