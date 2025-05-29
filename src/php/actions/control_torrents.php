<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\Action\TopicControl;
use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Legacy\Log;

$control_result = [
    'result' => 'В процессе регулировки раздач были ошибки. ' .
        'Для получения подробностей обратитесь к журналу событий.',
];

// Инициализируем контейнер.
$app = App::create('control.log');
$log = $app->getLogger();

try {
    $config = $app->getLegacyConfig();

    /** @var TopicControl $topicControl */
    $topicControl = $app->get(TopicControl::class);
    // Запускаем регулировку раздач.
    $topicControl->process(config: $config);

    $control_result['result'] = 'Регулировка раздач выполнена.';
} catch (RuntimeException $e) {
    $log->warning($e->getMessage());
} catch (Throwable $e) {
    $log->error($e->getMessage());
} finally {
    $log->info('-- DONE --');
}

// Добавляем записанный журнал.
$control_result['log'] = Log::get();

echo json_encode($control_result, JSON_UNESCAPED_UNICODE);
