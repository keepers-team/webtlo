<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Forum\Report\CreationMode;
use KeepersTeam\Webtlo\Forum\Report\Creator as ReportCreator;
use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\Storage\Table\Forums;

$reports_result = [
    'report' => '',
];

$output = '<br /><div>Нет или недостаточно данных для отображения.<br />Проверьте настройки, журнал и выполните обновление сведений.</div><br />';
try {
    // идентификатор подраздела
    $forumId = (int)($_POST['forum_id'] ?? -1);

    if ($forumId < 0) {
        throw new Exception("ERROR: Неправильный идентификатор подраздела ($forumId).");
    }

    // Инициализация и получение конфига.
    $app = AppContainer::create();
    $cfg = $app->getLegacyConfig();
    $log = $app->getLogger();

    /** @var Forums $forums */
    $forums = $app->get(Forums::class);

    /** @var ReportCreator $forumReports Создание отчётов */
    $forumReports = $app->get(ReportCreator::class);
    $forumReports->initConfig(CreationMode::UI);

    if ($forumId === 0) {
        // Сводный отчёт
        $output = $forumReports->getSummaryReport(true);
    } else {
        // Хранимые подразделы
        try {
            $forum = $forums->getForum(forumId: $forumId);
            if (null === $forum) {
                throw new RuntimeException("Нет данных о хранимом подразделе №$forumId");
            }

            $forumReports->fillStoredValues(forumId: $forumId);
            $reportMessages = $forumReports->getForumReport(forum: $forum);

            $output = $forumReports->prepareReportsMessages($reportMessages);
        } catch (RuntimeException $e) {
            $log->notice(
                'Формирование отчёта для подраздела {forum} прекращено. Причина {error}',
                ['forum' => $forumId, 'error' => $e->getMessage()]
            );
        } catch (Throwable $e) {
            $log->warning(
                'Формирование отчёта для подраздела {forum} прекращено. Причина {error}',
                ['forum' => $forumId, 'error' => $e->getMessage()]
            );
        }
    }
    $log->info('-- DONE --');
} catch (Throwable $e) {
    $message = $e->getMessage();
    if (isset($log)) {
        $log->error($message);
        $log->info('-- DONE --');
    }

    $output.= '<br />' . $message;
}

$reports_result['report'] = $output;
$reports_result['log']    = Log::get();

echo json_encode($reports_result, JSON_UNESCAPED_UNICODE);
