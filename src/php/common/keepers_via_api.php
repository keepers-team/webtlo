<?php

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Config\Validate as ConfigValidate;
use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Enum\UpdateStatus;
use KeepersTeam\Webtlo\Forum\AccessCheck;
use KeepersTeam\Webtlo\Legacy\Db;
use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\Module\CloneTable;
use KeepersTeam\Webtlo\Module\LastUpdate;
use KeepersTeam\Webtlo\Timers;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\External\ApiClient;

include_once dirname(__FILE__) . '/../../vendor/autoload.php';
include_once dirname(__FILE__) . '/../classes/reports.php';

App::init();

$app_container = AppContainer::create('keepers.log');

Timers::start('update_keepers');

// получение настроек
if (!isset($cfg)) {
    $cfg = App::getSettings();
}

if (isset($checkEnabledCronAction)) {
    $checkEnabledCronAction = $cfg['automation'][$checkEnabledCronAction] ?? -1;
    if ($checkEnabledCronAction == 0) {
        throw new Exception('Notice: Автоматическое обновление списков других хранителей отключено в настройках.');
    }
}

$user = ConfigValidate::checkUser($cfg);

Log::append('Info: Начато обновление списков раздач хранителей...');

// Параметры таблиц.
$tabForumsOptions = CloneTable::create('ForumsOptions', [], 'forum_id');
$tabKeepersList   = CloneTable::create(
    'KeepersLists',
    ['topic_id', 'keeper_id', 'keeper_name', 'posted', 'complete'],
    'topic_id'
);

// Список ид хранимых подразделов.
$keptForums = array_keys($cfg['subsections'] ?? []);
$forumKeys  = KeysObject::create($keptForums);

// Удалим данные о нехранимых более подразделах.
Db::query_database("DELETE FROM $tabForumsOptions->origin WHERE $tabForumsOptions->primary NOT IN ($forumKeys->keys)", $forumKeys->values);


// Список ид обновлений подразделов.
$keptForumsUpdate = array_map(fn ($el) => 100000 + $el, $keptForums);
$updateStatus = new LastUpdate($keptForumsUpdate);
$updateStatus->checkMarkersLess(7200);

// Если количество маркеров не совпадает, обнулим имеющиеся.
if ($updateStatus->getLastCheckStatus() === UpdateStatus::MISSED) {
    Db::query_database("DELETE FROM UpdateTime WHERE id BETWEEN 100000 AND 200000");
}
// Проверим минимальную дату обновления данных других хранителей.
if ($updateStatus->getLastCheckStatus() === UpdateStatus::EXPIRED) {
    Log::append(sprintf(
        'Notice: Обновление списков других хранителей и сканирование форума не требуется. Дата последнего выполнения %s',
        date("d.m.y H:i", $updateStatus->getLastCheckUpdateTime())
    ));
    return;
}

// Подключаемся к форуму.
if (!isset($reports)) {
    $reports = new Reports(
        $cfg['forum_address'],
        $user
    );
    // применяем таймауты
    $reports->curl_setopts($cfg['curl_setopt']['forum']);
}

if ($unavailable = $reports->check_access()) {
    if (in_array($unavailable, [AccessCheck::NOT_AUTHORIZED, AccessCheck::USER_CANDIDATE])) {
        Log::append($unavailable->value);
        return;
    }
}

$apiReportClient = new ApiReportClient($cfg);

$apiClient = $app_container->getApiClient();
$keepersList = $apiClient->getKeepersList()->keepers;
$keeperNicks = [];
foreach ($keepersList as $keeper) {
    if (!$keeper->isCandidate) $keeperNicks[$keeper->keeperId] = $keeper->keeperName;
}

$forumsScanned = 0;
$keeperIds     = [];
$forumsParams  = [];
if (isset($cfg['subsections'])) {
    // получаем данные
    foreach ($cfg['subsections'] as $forum_id => $subsection) {
        $forum_reports = $apiReportClient->get_forum_reports($forum_id, 'status');
        foreach ($forum_reports as $report) {
            $keeper_id = $report['user_id'];
            if ($keeper_id != $user->userId && array_key_exists($keeper_id, $keeperNicks)) {
                $keeperIds[] = $keeper_id;
            } else {
                continue;
            }
            $posted = '';  // TODO: Use NULL somehow?
            $nickname = $keeperNicks[$keeper_id];
            $done_topic_ids        = [];
            $downloading_topic_ids = [];

            $preparedTopics = [];
            foreach ($report['kept_releases'] as $stored_topic) {
                if (!($stored_topic[1] & $apiReportClient->KEEPING_STATUSES['reported_by_api'])) {
                    continue;
                }
                $complete = 1;
                if ($stored_topic[1] & $apiReportClient->KEEPING_STATUSES['downloading']) {
                    $complete = 0;
                }
                $preparedTopics[] = array_combine($tabKeepersList->keys, [
                    $stored_topic[0],
                    $keeper_id,
                    $nickname,
                    $posted,
                    $complete,
                ]);
            }
            $tabKeepersList->cloneFillChunk($preparedTopics, 200);
        }
        $forumsScanned++;
    }
}


// записываем изменения в локальную базу
$count_kept_topics = $tabKeepersList->cloneCount();
if ($count_kept_topics > 0) {
    Log::append(sprintf(
        'Просканировано подразделов: %d шт, хранителей: %d, хранимых раздач: %d шт.',
        $forumsScanned,
        count(array_unique($keeperIds)),
        $count_kept_topics
    ));
    Log::append('Запись в базу данных списков раздач хранителей...');

    // Переносим данные из временной таблицы в основную.
    $tabKeepersList->moveToOrigin();

    // Удаляем неактуальные записи списков.
    Db::query_database(
        "DELETE FROM $tabKeepersList->origin WHERE topic_id || keeper_id NOT IN (
            SELECT upd.topic_id || upd.keeper_id
            FROM $tabKeepersList->clone AS tmp
            LEFT JOIN $tabKeepersList->origin AS upd ON tmp.topic_id = upd.topic_id AND tmp.keeper_id = upd.keeper_id
            WHERE upd.topic_id IS NOT NULL
        )"
    );
}
Log::append('Info: Обновление списков раздач хранителей завершено за ' . Timers::getExecTime('update_keepers'));
