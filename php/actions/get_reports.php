<?php

$reports_result = [
    'report' => '',
];
try {
    include_once dirname(__FILE__) . '/../common.php';
    include_once dirname(__FILE__) . '/../classes/reports.php';
    include_once dirname(__FILE__) . '/../classes/ReportCreator.php';

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
        $webtlo,
        $reports
    );
    $forumReports->setMode('UI');

    if ($forum_id === 0) {
        // Сводный отчёт
        $output = $forumReports->getSummaryReport();
    } else {
        // Хранимые подразделы
        $forumReport = $forumReports->getForumReport($forum_id);
        $output = $forumReports->prepareReportsMessages($forumReport);
    }

    $reports_result['report'] = $output;
} catch (Exception $e) {
    Log::append($e->getMessage());
    $reports_result['report'] = "<br /><div>Нет или недостаточно данных для отображения.<br />Проверьте настройки, журнал и выполните обновление сведений.</div><br />";
}

// выводим лог
$reports_result['log'] = Log::get();

echo json_encode($reports_result, JSON_UNESCAPED_UNICODE);
