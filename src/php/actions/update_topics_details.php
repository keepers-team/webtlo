<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\Update\TopicsDetails;

$result = [];
try {
    $app = App::create('update.log');
    $log = $app->getLogger();

    // Обновление раздач за раз. Меньшее число, для наглядности.
    $updateDetailsPerRun = 1500;

    /** @var TopicsDetails $detailsClass */
    $detailsClass = $app->get(TopicsDetails::class);

    // Заполняем данные о раздачах.
    $detailsClass->update($updateDetailsPerRun);

    $log->info('-- DONE --');
} catch (Throwable $e) {
    if (isset($log)) {
        $log->error($e->getMessage());
    } else {
        Log::append($e->getMessage());
    }
}

$result['log'] = Log::get();

echo json_encode($result, JSON_UNESCAPED_UNICODE);
