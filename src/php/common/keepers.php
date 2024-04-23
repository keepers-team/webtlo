<?php

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Config\Validate as ConfigValidate;
use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Enum\UpdateStatus;
use KeepersTeam\Webtlo\Forum\AccessCheck;
use KeepersTeam\Webtlo\Legacy\Db;
use KeepersTeam\Webtlo\Module\CloneTable;
use KeepersTeam\Webtlo\Module\LastUpdate;
use KeepersTeam\Webtlo\Tables\KeepersLists;
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
    $checkEnabledCronAction = $cfg['automation'][$checkEnabledCronAction] ?? -1;
    if ($checkEnabledCronAction == 0) {
        $log->notice('KeepersLists. Автоматическое обновление списков других хранителей отключено в настройках.');

        return;
    }
}

// Список ид хранимых подразделов.
$keptForums = array_keys($cfg['subsections'] ?? []);
$forumKeys = KeysObject::create($keptForums);

// Отправляем ли отчёты на форум.
$sendForumReport = (bool)($cfg['reports']['send_report_forum'] ?? true);
$forceReadForums = false;

// TODO Убрать, когда откажемся от отчётов на форуме.
// Если включена отправка отчётов на форум, то нужно иметь данные о своих постах в теме хранимых подразделов.
if ($sendForumReport) {
    $countForumOptions = Db::query_count(
        "SELECT COUNT(1) FROM ForumsOptions WHERE forum_id IN ($forumKeys->keys)",
        $forumKeys->values
    );

    // Если количество хранимых подразделов не совпадает с количеством сканированных подразделов - нужно сканировать.
    if ($countForumOptions !== count($keptForums)) {
        $forceReadForums = true;
    }
}

/** Список тем с отчётами на форуме. */
$reportTopics = null;

$keepersUpdatedByApi = false;
// Тут решаем, использовать API отчётов или работать только через форум.
if (!empty($cfg['reports']['keepers_load_api'])) {
    /** @var KeepersReports $keepersReports */
    $keepersReports = $app->get(KeepersReports::class);

    $keepersUpdatedByApi = $keepersReports->update($cfg);
    if ($keepersUpdatedByApi) {
        LastUpdate::setTime(UpdateMark::FORUM_SCAN->value);
    }

    $reportTopics = $keepersReports->getReportTopics();
}

if (!$forceReadForums && $keepersUpdatedByApi) {
    $log->debug('KeepersLists. Списки получены из API. Сканирование форума не требуется.');

    return;
}

$user = ConfigValidate::checkUser($cfg);

$log->info('Forum. Начато сканирование списков раздач хранителей...');

// Параметры таблиц.
$tabForumsOptions = CloneTable::create('ForumsOptions', [], 'forum_id');

/** @var KeepersLists $tableKeepers */
$tableKeepers = $app->get(KeepersLists::class);

if (!$keepersUpdatedByApi) {
    // Удалим данные о нехранимых более подразделах.
    Db::query_database(
        "DELETE FROM $tabForumsOptions->origin WHERE $tabForumsOptions->primary NOT IN ($forumKeys->keys)",
        $forumKeys->values
    );
}


// Список ид обновлений подразделов.
$keptForumsUpdate = array_map(fn ($el) => 100000 + $el, $keptForums);
$updateStatus = new LastUpdate($keptForumsUpdate);
$updateStatus->checkMarkersLess(7200);

// Если количество маркеров не совпадает, обнулим имеющиеся.
if ($updateStatus->getLastCheckStatus() === UpdateStatus::MISSED) {
    $tableKeepers->clearLists();
}

// Проверим минимальную дату обновления данных других хранителей.
if (!$forceReadForums && $updateStatus->getLastCheckStatus() === UpdateStatus::EXPIRED) {
    $log->notice(sprintf(
        'Forum. Обновление списков других хранителей и сканирование форума не требуется. Дата последнего выполнения %s',
        date('d.m.y H:i', $updateStatus->getLastCheckUpdateTime())
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
        $log->info($unavailable->value);

        return;
    }
}

$forumsScanned = 0;
$keeperIds     = [];
$forumsParams  = [];
if (!empty($cfg['subsections'])) {
    // получаем данные
    foreach ($cfg['subsections'] as $forum_id => $subsection) {
        // Ищем ид темы со списками в данных полученных из API отчётов (если они есть).
        if (null !== $reportTopics) {
            $forumReportTopicId = $reportTopics->getReportTopicId((int)$forum_id);
            if (null === $forumReportTopicId) {
                $log->debug('Missing reportForumTopicId', ['forumId' => $forum_id]);
            }
        }

        // Ищем ид темы со списками на форуме.
        if (empty($forumReportTopicId)) {
            $forumReportTopicId = $reports->search_topic_id($subsection['na']);
        }

        if (empty($forumReportTopicId)) {
            $log->warning(sprintf(
                'Не удалось найти тему со списками для подраздела № %d (%s).',
                $forum_id,
                $subsection['na']
            ));
            continue;
        } else {
            $forumsScanned++;
        }

        // Ищем списки хранимого другими хранителями.
        $keepers = $reports->scanning_viewtopic($forumReportTopicId);
        if (!empty($keepers)) {
            $userPosts = [];
            foreach ($keepers as $keeper) {
                // Записываем свои посты, для формирования отчётов.
                if ($keeper['user_id'] == $user->userId) {
                    $userPosts[] = $keeper['post_id'];
                }

                if (empty($keeper['topics_ids'])) {
                    continue;
                }
                $keeperIds[] = $keeper['user_id'];

                // Если уже обновили списки через API, то нет смысла записывать их ещё раз.
                if (!$keepersUpdatedByApi) {
                    foreach ($keeper['topics_ids'] as $complete => $keeperTopicsIDs) {
                        foreach ($keeperTopicsIDs as $topic_id) {
                            $tableKeepers->addKeptTopic(
                                (int)$topic_id,
                                (int)$keeper['user_id'],
                                $keeper['nickname'],
                                (int)$keeper['posted'],
                                (int)$complete,
                            );

                            unset($topic_id);
                        }
                        unset($complete, $keeperTopicsIDs);
                    }

                    $tableKeepers->fillTempTable();
                }
                unset($keeper);
            }

            if (!$keepersUpdatedByApi) {
                LastUpdate::setTime(100000 + $forum_id);
            }

            // Сохраним данных о своих постах в теме по подразделу.
            $forumsParams[$forum_id] = [
                'forum_id'       => $forum_id,
                'topic_id'       => $forumReportTopicId,
                'author_id'      => $keepers[0]['user_id'] ?? 0,
                'author_name'    => $keepers[0]['nickname'] ?? '',
                'author_post_id' => $keepers[0]['post_id'] ?? 0,
                'post_ids'       => json_encode($userPosts),
            ];

            unset($keepers);
        }
    }
}

// Записываем дополнительные данные о хранимых подразделах, в т.ч. ид своих постов.
if (count($forumsParams)) {
    $tabForumsOptions->cloneFillChunk($forumsParams, 200);
    // Переносим данные из временной таблицы в основную.
    $tabForumsOptions->moveToOrigin();
    $tabForumsOptions->clearUnusedRows();

    LastUpdate::setTime(UpdateMark::FORUM_SCAN->value);
}

// Записываем данные о хранимых раздачах в основную таблицу БД.
$tableKeepers->moveToOrigin($forumsScanned, count(array_unique($keeperIds)));

$log->info('KeepersLists. Обновление списков раздач хранителей завершено за ' . Timers::getExecTime('update_keepers'));
