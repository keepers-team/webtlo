<?php

use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Enum\UpdateStatus;
use KeepersTeam\Webtlo\Config\Validate as ConfigValidate;
use KeepersTeam\Webtlo\Forum\AccessCheck;
use KeepersTeam\Webtlo\Module\CloneTable;
use KeepersTeam\Webtlo\Module\LastUpdate;

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/reports.php';

Timers::start('update_keepers');

// получение настроек
if (!isset($cfg)) {
    $cfg = get_settings();
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

$forumsScanned = 0;
$keeperIds     = [];
$forumsParams  = [];
if (isset($cfg['subsections'])) {
    // получаем данные
    foreach ($cfg['subsections'] as $forum_id => $subsection) {
        // ид темы со списками хранителей.
        $forum_topic_id = $reports->search_topic_id($subsection['na']);

        if (empty($forum_topic_id)) {
            Log::append(sprintf(
                'Error: Не удалось найти тему со списками для подраздела № %d (%s).',
                $forum_id,
                $subsection['na']
            ));
            continue;
        } else {
            $forumsScanned++;
        }

        // Ищем списки хранимого другими хранителями.
        $keepers = $reports->scanning_viewtopic($forum_topic_id);
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

                $preparedTopics = [];
                foreach ($keeper['topics_ids'] as $complete => $keeperTopicsIDs) {
                    foreach ($keeperTopicsIDs as $topic_id) {
                        $preparedTopics[] = array_combine($tabKeepersList->keys, [
                            $topic_id,
                            $keeper['user_id'],
                            $keeper['nickname'],
                            $keeper['posted'],
                            $complete,
                        ]);

                        unset($topic_id);
                    }
                    unset($complete, $keeperTopicsIDs);
                }
                $tabKeepersList->cloneFillChunk($preparedTopics, 200);

                unset($keeper, $preparedTopics);
            }

            // Сохраним данных о своих постах в теме по подразделу.
            $forumsParams[$forum_id] = [
                'forum_id'       => $forum_id,
                'topic_id'       => $forum_topic_id,
                'author_id'      => $keepers[0]['user_id'] ?? 0,
                'author_name'    => $keepers[0]['nickname'] ?? '',
                'author_post_id' => $keepers[0]['post_id'] ?? 0,
                'post_ids'       => json_encode($userPosts),
            ];

            LastUpdate::setTime(100000 + $forum_id);
            unset($keepers);
        }
    }
}

// Записываем дополнительные данные о хранимых подразделах, в т.ч. ид своих постов.
if (count($forumsParams)) {
    $tabForumsOptions->cloneFillChunk($forumsParams, 200);
    // Переносим данные из временной таблицы в основную.
    $tabForumsOptions->moveToOrigin();

    LastUpdate::setTime(UpdateMark::FORUM_SCAN->value);
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
