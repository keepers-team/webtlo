<?php

include_once dirname(__FILE__) . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Tables\Topics;
use KeepersTeam\Webtlo\Update\TopicDetails;

$app = AppContainer::create();

$logger = $app->getLogger();

/** @var Topics $topics */
$topics = $app->get(Topics::class);

$countUnnamed = $topics->countUnnamed();
if (!$countUnnamed) {
    $logger->notice('Обновление дополнительных сведений о раздачах не требуется.');
    return;
}

/** @var TopicDetails $detailsClass */
$detailsClass = $app->get(TopicDetails::class);
$detailsClass->fillDetails($countUnnamed, $updateDetailsPerRun ?? 5000);
$details = $detailsClass->getResult();

if (null !== $details) {
    $logger->info(sprintf(
        'Обновление дополнительных сведений о раздачах завершено за %s.',
        Helper::convertSeconds($details->execFull)
    ));
    $logger->info(sprintf(
        'Раздач обновлено %d из %d.',
        $details->before - $details->after,
        $details->before
    ));
    $logger->debug('detailsResult', (array)$details);
}
