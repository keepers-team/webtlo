<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Module\Report\CreateReport;
use KeepersTeam\Webtlo\Module\Report\CreationMode;
use KeepersTeam\Webtlo\Storage\Table\Forums;

$output = '<br /><div>Нет или недостаточно данных для отображения.<br />Проверьте настройки, журнал и выполните обновление сведений.</div><br />';

// Подключаем контейнер.
$app = App::create();
$log = $app->getLogger();

try {
    // идентификатор подраздела
    $forumId = (int) ($_POST['forum_id'] ?? -1);

    if ($forumId < 0) {
        throw new RuntimeException("Неправильный идентификатор подраздела ($forumId).");
    }

    /** @var Forums $forums */
    $forums = $app->get(Forums::class);

    /** @var CreateReport $createReport Создание отчётов */
    $createReport = $app->get(CreateReport::class);
    $createReport->initConfig(CreationMode::UI);

    if ($forumId === 0) {
        // Сводный отчёт
        $output = $createReport->getSummaryReport(true);
    } else {
        // Хранимые подразделы
        try {
            $forum = $forums->getForum(forumId: $forumId);
            if ($forum === null) {
                throw new RuntimeException("Нет данных о хранимом подразделе №$forumId");
            }

            $createReport->fillStoredValues(forumId: $forumId);
            $reportMessages = $createReport->getForumReport(forum: $forum);

            $output = $createReport->prepareReportsMessages($reportMessages);
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
} catch (Throwable $e) {
    $message = $e->getMessage();
    $log->error($message);

    $output .= '<br />' . $message;
} finally {
    $log->info('-- DONE --');
}

echo App::decorateJsonResponse(['report' => $output]);
