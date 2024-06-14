<?php

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Module\LastUpdate;
use KeepersTeam\Webtlo\Timers;
use KeepersTeam\Webtlo\Update\KeepersReports;

include_once dirname(__FILE__) . '/../../vendor/autoload.php';
include_once dirname(__FILE__) . '/../classes/reports.php';

$app = AppContainer::create('keepers.log');
$log = $app->getLogger();

Timers::start('update_keepers');

// Получение настроек.
$cfg = $app->getLegacyConfig();

if (isset($checkEnabledCronAction)) {
    if (!Helper::isScheduleActionEnabled($cfg, $checkEnabledCronAction)) {
        $log->notice('KeepersLists. Автоматическое обновление списков других хранителей отключено в настройках.');

        return;
    }
}


// Находим список игнорируемых хранителей.
$excludedKeepers = KeepersReports::getExcludedKeepersList($cfg);
if (count($excludedKeepers)) {
    $log->debug('KeepersLists. Исключены хранители', $excludedKeepers);
}

/** @var KeepersReports $keepersReports */
$keepersReports = $app->get(KeepersReports::class);
$keepersReports->setExcludedKeepers($excludedKeepers);

$keepersUpdatedByApi = $keepersReports->update($cfg);
if ($keepersUpdatedByApi) {
    LastUpdate::setTime(UpdateMark::FORUM_SCAN->value);
}

$log->info('KeepersLists. Обновление списков раздач хранителей завершено за ' . Timers::getExecTime('update_keepers'));
