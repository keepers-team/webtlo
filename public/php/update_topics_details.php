<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Enum\LogFile;
use KeepersTeam\Webtlo\Update\TopicsDetails;

$app = App::create(LogFile::Update);
$log = $app->getLogger();

try {
    // Обновление раздач за раз. Меньшее число, для наглядности.
    $updateDetailsPerRun = 1500;

    /** @var TopicsDetails $detailsClass */
    $detailsClass = $app->get(TopicsDetails::class);

    // Заполняем данные о раздачах.
    $detailsClass->update($updateDetailsPerRun);
} catch (Throwable $e) {
    $log->error($e->getMessage());
} finally {
    $log->info('-- DONE --');
}

echo App::decorateJsonResponse();
