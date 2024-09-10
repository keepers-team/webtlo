<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\Action\TopicControl;
use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Legacy\Log;

$control_result = [
    'result' => 'В процессе регулировки раздач были ошибки. ' .
        'Для получения подробностей обратитесь к журналу событий.',
];

try {
    // Инициализируем контейнер.
    $app = AppContainer::create('control.log');
    $log = $app->getLogger();

    $config = $app->getLegacyConfig();

    /** @var TopicControl $topicControl */
    $topicControl = $app->get(TopicControl::class);
    // Запускаем регулировку раздач.
    $topicControl->process(config: $config);

    $control_result['result'] = 'Регулировка раздач выполнена.';
} catch (RuntimeException $e) {
    if (isset($log)) {
        $log->warning($e->getMessage());
    }
} catch (Throwable $e) {
    if (isset($log)) {
        $log->error($e->getMessage());
    }
}

// Добавляем записанный журнал.
$control_result['log'] = Log::get();

echo json_encode($control_result, JSON_UNESCAPED_UNICODE);
