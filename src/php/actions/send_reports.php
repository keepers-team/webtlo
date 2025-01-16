<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\Action\SendReports;
use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Legacy\Log;

$reports_result = [
    'result' => '',
];

// Создаём контейнер и пишем в лог.
$app = App::create('reports.log');
$log = $app->getLogger();

try {
    /** @var SendReports $action Отправка отчётов. */
    $action = $app->get(SendReports::class);
    $action->process();
} catch (Throwable $e) {
    $log->error($e->getMessage());

    $reports_result['result'] = 'В процессе отправки отчётов были ошибки. ' .
        'Для получения подробностей обратитесь к журналу событий.';
} finally {
    $log->info('-- DONE --');
}

// Выводим лог.
$reports_result['log'] = Log::get();

echo json_encode($reports_result, JSON_UNESCAPED_UNICODE);
