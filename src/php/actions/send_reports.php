<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Legacy\Log;

$reports_result = [
    'result' => '',
];

// Создаём контейнер и пишем в лог.
$app = AppContainer::create('reports.log');
$log = $app->getLogger();

try {
    // дёргаем скрипт
    include_once dirname(__FILE__) . '/../common/reports.php';

    $log->info('-- DONE --');
} catch (Throwable $e) {
    $log->error($e->getMessage());

    $reports_result['result'] = 'В процессе отправки отчётов были ошибки. ' .
        'Для получения подробностей обратитесь к журналу событий.';
}

// Выводим лог.
$reports_result['log'] = Log::get();

echo json_encode($reports_result, JSON_UNESCAPED_UNICODE);
