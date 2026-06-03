<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\Action\TopicControl;
use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Enum\LogFile;

$control_result = 'В процессе регулировки раздач были ошибки. ' .
    'Для получения подробностей обратитесь к журналу событий.';

// Инициализируем контейнер.
$app = App::create(LogFile::Control);
$log = $app->getLogger();

try {
    /** @var TopicControl $topicControl */
    $topicControl = $app->get(TopicControl::class);
    // Запускаем регулировку раздач.
    $topicControl->process();

    $control_result = 'Регулировка раздач выполнена.';
} catch (RuntimeException $e) {
    $log->warning($e->getMessage());
} catch (Throwable $e) {
    $log->error($e->getMessage());
} finally {
    $log->info('-- DONE --');
}

echo App::decorateJsonResponse($control_result);
