<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\Action\SendKeeperReports;
use KeepersTeam\Webtlo\App;

$reports_result = [
    'result' => '',
];

// Создаём контейнер и пишем в лог.
$app = App::create('reports.log');
$log = $app->getLogger();

try {
    // Проверяем наличие запроса фронта о необходимости отправки чистых отчётов.
    $postData = json_decode((string) file_get_contents('php://input'), true);

    $reportOverride = null;
    if (isset($postData['cleanOverride']) && $postData['cleanOverride'] === true) {
        $reportOverride = true;

        $log->notice('Получен сигнал для отправки "чистых" отчётов.');
    }
    unset($postData);

    /** @var SendKeeperReports $action Отправка отчётов. */
    $action = $app->get(SendKeeperReports::class);
    $action->process(reportOverride: $reportOverride);
} catch (Throwable $e) {
    $log->error($e->getMessage());

    $reports_result['result'] = 'В процессе отправки отчётов были ошибки. ' .
        'Для получения подробностей обратитесь к журналу событий.';
} finally {
    $log->info('-- DONE --');
}

// Выводим лог.
$reports_result['log'] = $app->getLoggerRecords();

echo json_encode($reports_result, JSON_UNESCAPED_UNICODE);
