<?php

include_once dirname(__FILE__) . '/../../vendor/autoload.php';
include_once dirname(__FILE__) . '/../classes/api.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\Module\TopicDetails;

$countUnnamed = TopicDetails::countUnnamed();
if (!$countUnnamed) {
    Log::append('Notice: Обновление дополнительных сведений о раздачах не требуется.');
    return;
}

App::init();

// получение настроек
if (!isset($cfg)) {
    $cfg = App::getSettings();
}

// подключаемся к api
if (!isset($api)) {
    $api = new Api($cfg['api_address'], $cfg['api_key']);
    // применяем таймауты
    $api->setUserConnectionOptions($cfg['curl_setopt']['api']);
}

$detailsClass = new TopicDetails($api);
$detailsClass->fillDetails($countUnnamed, $updateDetailsPerRun ?? 5000);
$details = $detailsClass->getResult();



if (null !== $details) {
    Log::append(sprintf(
        'Обновление дополнительных сведений о раздачах завершено за %s.',
        Helper::convertSeconds($details->execFull)
    ));
    Log::append(sprintf(
        'Раздач обновлено %d из %d.',
        $details->before - $details->after,
        $details->before
    ));
}