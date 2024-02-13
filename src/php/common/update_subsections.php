<?php

include_once dirname(__FILE__) . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\ForumTopic;
use KeepersTeam\Webtlo\Legacy\Db;
use KeepersTeam\Webtlo\Module\CloneTable;
use KeepersTeam\Webtlo\Module\LastUpdate;
use KeepersTeam\Webtlo\Module\Topics;
use KeepersTeam\Webtlo\Timers;
use KeepersTeam\Webtlo\Update\ForumTree;

$app = AppContainer::create('update.log');

// Получение настроек.
$cfg = $app->getLegacyConfig();

if (isset($checkEnabledCronAction)) {
    $checkEnabledCronAction = $cfg['automation'][$checkEnabledCronAction] ?? -1;
    if ($checkEnabledCronAction == 0) {
        throw new RuntimeException(
            'Notice: Автоматическое обновление сведений о раздачах в хранимых подразделах отключено в настройках.'
        );
    }
}

/** @var ForumTree $forumTree Обновляем дерево подразделов. */
$forumTree = $app->get(ForumTree::class);
$forumTree->update();

$logger = $app->getLogger();

// Подключаемся к Api.
$apiClient = $app->getApiClient();

Timers::start('topics_update');
$logger->info("Начато обновление сведений о раздачах в хранимых подразделах...");

// Параметры таблиц.
$tabTime = CloneTable::create('UpdateTime');
// Обновляемые раздачи.
$tabTopicsUpdate = CloneTable::create(
    'Topics',
    [
        'id',
        'forum_id',
        'seeders',
        'status',
        'seeders_updates_today',
        'seeders_updates_days',
        'keeping_priority',
        'poster',
        'seeder_last_seen',
    ],
    'id',
    'Update'
);
// Новые раздачи.
$tabTopicsRenew = CloneTable::create(
    'Topics',
    [
        'id',
        'forum_id',
        'name',
        'info_hash',
        'seeders',
        'size',
        'status',
        'reg_time',
        'seeders_updates_today',
        'seeders_updates_days',
        'keeping_priority',
        'poster',
        'seeder_last_seen',
    ],
    'id',
    'Renew'
);
// Сиды-Хранители раздач.
$tabKeepers = CloneTable::create('KeepersSeeders', [], 'topic_id');

$forumsUpdateTime = [];
$noUpdateForums   = [];
if (isset($cfg['subsections'])) {
    $subsections = array_keys($cfg['subsections']);
    sort($subsections);

    // получим список всех хранителей
    $keepersUserData = $apiClient->getKeepersList();
    $keepersUserData = array_combine(
        array_map(fn($k) => $k->keeperId, $keepersUserData->keepers),
        array_map(fn($k) => $k->keeperName, $keepersUserData->keepers)
    );

    // обновим каждый хранимый подраздел
    foreach ($subsections as $forum_id) {
        $forum_id = (int)$forum_id;
        // получаем дату предыдущего обновления
        $update_time = LastUpdate::getTime($forum_id);

        // если не прошёл час
        if (time() - $update_time < 3600) {
            $noUpdateForums[] = $forum_id;
            continue;
        }

        Timers::start("update_forum_$forum_id");
        // получаем данные о раздачах
        $topicResponse = $apiClient->getForumTopicsData($forum_id);
        if ($topicResponse instanceof ApiError) {
            $logger->error(
                sprintf(
                    'Не получены данные о подразделе №%d (%d %s)',
                    $forum_id,
                    $topicResponse->code,
                    $topicResponse->text
                )
            );
            continue;
        }

        // количество и вес раздач
        $topics_count = count($topicResponse->topics);
        $topics_size  = $topicResponse->totalSize;

        // запоминаем время обновления каждого подраздела
        $forumsUpdateTime[$forum_id]['ud'] = (int)$topicResponse->updateTime->format('U');

        // День предыдущего обновления сведений.
        $previousUpdateTime = (new DateTimeImmutable())->setTimestamp($update_time)->setTime(0, 0);

        // разница в днях между текущим и прошлым обновлениями сведений
        $daysDiffAdjusted = (int)$topicResponse->updateTime->diff($previousUpdateTime)->format('%d');

        // разбиваем result по 500 раздач
        /** @var ForumTopic[][] $topicsChunks */
        $topicsChunks = array_chunk($topicResponse->topics, 500);

        foreach ($topicsChunks as $topicsChunk) {
            // получаем данные о раздачах за предыдущее обновление
            $selectTopics = KeysObject::create(array_map(fn($tp) => $tp->id, $topicsChunk));

            $topics_data_previous = Db::query_database(
                "
                    SELECT id, seeders, reg_time, seeders_updates_today, seeders_updates_days
                    FROM Topics
                    WHERE id IN ($selectTopics->keys)
                ",
                $selectTopics->values,
                true,
                PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE
            );
            unset($selectTopics);

            $topicsKeepersFromForum = [];

            $dbTopicsKeepers = [];
            $db_topics_renew = $db_topics_update = [];
            // Перебираем раздачи.
            foreach ($topicsChunk as $topic) {
                // Пропускаем раздачи в невалидных статусах.
                if (!in_array($topic->status->value, Topics::VALID_STATUSES)) {
                    continue;
                }

                // Хранители раздачи
                if (!empty($topic->keepers)) {
                    $topicsKeepersFromForum[$topic->id] = $topic->keepers;
                }

                $days_update = 0;
                $sum_updates = 1;
                $sum_seeders = $topic->seeders;

                // запоминаем имеющиеся данные о раздаче в локальной базе
                $previous_data = $topics_data_previous[$topic->id] ?? [];

                $topicRegistered = (int)$topic->registered->format('U');
                // удалить перерегистрированную раздачу и раздачу с устаревшими сидами
                // в том числе, чтобы очистить значения сидов для старой раздачи
                if (
                    isset($previous_data['reg_time'])
                    && (int)$previous_data['reg_time'] !== $topicRegistered
                ) {
                    $topics_delete[]   = $topic->id;
                    $isTopicDataDelete = true;
                } else {
                    $isTopicDataDelete = false;
                }

                // Новая или обновлённая раздача
                if (
                    empty($previous_data)
                    || $isTopicDataDelete
                ) {
                    $db_topics_renew[$topic->id] = array_combine(
                        $tabTopicsRenew->keys,
                        [
                            $topic->id,
                            $forum_id,
                            '',
                            $topic->hash,
                            $sum_seeders,
                            $topic->size,
                            $topic->status->value,
                            $topicRegistered,
                            $sum_updates,
                            $days_update,
                            $topic->priority->value,
                            $topic->poster,
                            (int)$topic->lastSeeded->format('U'),
                        ]
                    );
                    unset($previous_data);
                    continue;
                }

                // алгоритм нахождения среднего значения сидов
                if ($cfg['avg_seeders']) {
                    $days_update = $previous_data['seeders_updates_days'];
                    // по прошествии дня
                    if ($daysDiffAdjusted > 0) {
                        $days_update++;
                    } else {
                        $sum_updates += $previous_data['seeders_updates_today'];
                        $sum_seeders += $previous_data['seeders'];
                    }
                }
                unset($previous_data);

                $db_topics_update[$topic->id] = array_combine(
                    $tabTopicsUpdate->keys,
                    [
                        $topic->id,
                        $forum_id,
                        $sum_seeders,
                        $topic->status->value,
                        $sum_updates,
                        $days_update,
                        $topic->priority->value,
                        $topic->poster,
                        (int)$topic->lastSeeded->format('U'),
                    ]
                );

                unset($topic, $topicRegistered);
            }
            unset($topicsChunk);

            if (!empty($topicsKeepersFromForum)) {
                foreach ($topicsKeepersFromForum as $keeperTopicID => $keepersIDs) {
                    foreach ($keepersIDs as $keeperID) {
                        if (isset($keepersUserData[$keeperID])) {
                            $dbTopicsKeepers[] = [
                                'topic_id'    => $keeperTopicID,
                                'keeper_id'   => $keeperID,
                                'keeper_name' => $keepersUserData[$keeperID],
                            ];
                        }
                    }
                }

                // обновление данных в базе о сидах-хранителях
                $tabKeepers->cloneFillChunk($dbTopicsKeepers, 250);
                unset($dbTopicsKeepers);
            }

            unset($topics_data_previous);

            // вставка данных в базу о новых раздачах
            if (count($db_topics_renew)) {
                $tabTopicsRenew->cloneFill($db_topics_renew);
            }
            unset($db_topics_renew);

            // обновление данных в базе о существующих раздачах
            if (count($db_topics_update)) {
                $tabTopicsUpdate->cloneFill($db_topics_update);
            }
            unset($db_topics_update);
        }
        unset($topics_data);

        $logger->debug(
            sprintf(
                'Обновление списка раздач подраздела № %d завершено за %s, %d шт',
                $forum_id,
                Timers::getExecTime("update_forum_$forum_id"),
                $topics_count
            )
        );
    }
}

if (count($noUpdateForums)) {
    $logger->notice(
        sprintf('Обновление списков раздач не требуется для подразделов №№ %s.', implode(', ', $noUpdateForums))
    );
}

// удаляем перерегистрированные раздачи
// чтобы очистить значения сидов для старой раздачи
if (isset($topics_delete)) {
    Topics::deleteTopicsByIds($topics_delete);
    unset($topics_delete);
}

$keepersSeedersCount = $tabKeepers->cloneCount();
if ($keepersSeedersCount > 0) {
    $logger->info('Запись в базу данных списка сидов-хранителей...');
    $tabKeepers->moveToOrigin();

    // Удалить ненужные записи.
    Db::query_database(
        "DELETE FROM $tabKeepers->origin WHERE topic_id || keeper_id NOT IN (
            SELECT ks.topic_id || ks.keeper_id
            FROM $tabKeepers->clone tmp
            LEFT JOIN $tabKeepers->origin ks ON tmp.topic_id = ks.topic_id AND tmp.keeper_id = ks.keeper_id
            WHERE ks.topic_id IS NOT NULL
        )"
    );

    $logger->info(sprintf('Записано %d хранимых раздач.', $keepersSeedersCount));
}

$countTopicsUpdate = $tabTopicsUpdate->cloneCount();
$countTopicsRenew  = $tabTopicsRenew->cloneCount();
if ($countTopicsUpdate > 0 || $countTopicsRenew > 0) {
    // переносим данные в основную таблицу
    $tabTopicsUpdate->moveToOrigin();
    $tabTopicsRenew->moveToOrigin();

    $in = implode(',', array_keys($forumsUpdateTime));
    Db::query_database("
        DELETE FROM Topics
        WHERE id IN (
            SELECT Topics.id
            FROM Topics
            LEFT JOIN $tabTopicsUpdate->clone tpu ON Topics.id = tpu.id
            LEFT JOIN $tabTopicsRenew->clone tpr ON Topics.id = tpr.id
            WHERE tpu.id IS NULL AND tpr.id IS NULL AND Topics.forum_id IN ($in)
        )
    ");

    // время последнего обновления для каждого подраздела
    $tabTime->cloneFillChunk($forumsUpdateTime);
    $tabTime->moveToOrigin();
    // Записываем время обновления.
    LastUpdate::setTime(UpdateMark::SUBSECTIONS->value);

    $logger->info(
        sprintf(
            'Обработано хранимых подразделов: %d шт, раздач в них %d',
            count($forumsUpdateTime),
            $countTopicsUpdate + $countTopicsRenew
        )
    );
}
$logger->info(sprintf('Обновление сведений о раздачах завершено за %s', Timers::getExecTime('topics_update')));
