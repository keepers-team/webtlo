<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Config\Validate as ConfigValidate;
use KeepersTeam\Webtlo\Forum\Report\CreationMode;
use KeepersTeam\Webtlo\Forum\Report\Creator as ReportCreator;
use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\Module\Forums;
use KeepersTeam\Webtlo\Timers;

$reports_result = [
    'report' => '',
];
try {
    // идентификатор подраздела
    $forum_id = (int)($_POST['forum_id'] ?? -1);

    if ($forum_id < 0) {
        throw new Exception("Error: Неправильный идентификатор подраздела ($forum_id)");
    }

    // получение настроек
    $cfg  = App::getSettings();
    $user = ConfigValidate::checkUser($cfg);

    // проверка настроек
    if (empty($cfg['subsections'])) {
        throw new Exception("Error: Не выбраны хранимые подразделы");
    }

    // Создание отчётов.
    $forumReports = new ReportCreator(
        $cfg,
        $user
    );
    $forumReports->initConfig(CreationMode::UI);

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
            $forumReports->fillStoredValues($forum_id);
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
        'forum'  => $forum_id,
        'create' => Timers::getExecTime('create_report'),
    ]));

    $reports_result['report'] = $output;
} catch (Exception $e) {
    Log::append($e->getMessage());
    $reports_result['report'] = "<br /><div>Нет или недостаточно данных для отображения.<br />Проверьте настройки, журнал и выполните обновление сведений.</div><br />";
}

// выводим лог
$reports_result['log'] = Log::get();

echo json_encode($reports_result, JSON_UNESCAPED_UNICODE);
