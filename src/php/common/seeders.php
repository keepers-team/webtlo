<?php

include_once dirname(__FILE__) . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\ForumTopic;
use KeepersTeam\Webtlo\Legacy\Db;
use KeepersTeam\Webtlo\Module\CloneTable;
use KeepersTeam\Webtlo\Module\LastUpdate;
use KeepersTeam\Webtlo\Module\Topics;
use KeepersTeam\Webtlo\Timers;
use KeepersTeam\Webtlo\Update\ForumTree;

$app = AppContainer::create('seeders.log');

/** @var ForumTree $forumTree Обновляем дерево подразделов. */
$forumTree = $app->get(ForumTree::class);
$forumTree->update();

// получаем список подразделов
$forums_ids = Db::query_database(
    "SELECT id FROM Forums WHERE quantity > 0 AND size > 0 ORDER BY id",
    [],
    true,
    PDO::FETCH_COLUMN
);

if (empty($forums_ids)) {
    throw new RuntimeException("Error: Не получен список подразделов");
}

// Получение настроек.
$cfg = $app->getLegacyConfig();

$logger = $app->getLogger();

// Подключаемся к Api.
$apiClient = $app->getApiClient();

Timers::start('topics_update');
$logger->info('Начато обновление сведений о раздачах всех подразделов...');

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

$forumsUpdateTime = [];
$noUpdateForums   = [];

$subsections = array_keys($cfg['subsections'] ?? []);

// обновим каждый хранимый подраздел
foreach ($forums_ids as $forum_id) {
    // Пропускаем хранимые подразделы/
    if (in_array($forum_id, $subsections)) {
        continue;
    }

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

        $db_topics_renew = $db_topics_update = [];
        // Перебираем раздачи.
        foreach ($topicsChunk as $topic) {
            // Пропускаем раздачи в невалидных статусах.
            if (!in_array($topic->status->value, Topics::VALID_STATUSES)) {
                continue;
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
            'Список раздач подраздела № %-4d обновлён за %2s, %4d шт',
            $forum_id,
            Timers::getExecTime("update_forum_$forum_id"),
            $topics_count
        )
    );
}

if (count($noUpdateForums)) {
    $logger->info(sprintf(
        'Notice: Обновление списков раздач не требуется для подразделов №№ %s.',
        implode(', ', $noUpdateForums)
    ));
}

// удаляем перерегистрированные раздачи
// чтобы очистить значения сидов для старой раздачи
if (isset($topics_delete)) {
    Topics::deleteTopicsByIds($topics_delete);
    unset($topics_delete);
}


$countTopicsUpdate = $tabTopicsUpdate->cloneCount();
$countTopicsRenew  = $tabTopicsRenew->cloneCount();
if ($countTopicsUpdate > 0 || $countTopicsRenew > 0) {
    // переносим данные в основную таблицу
    $tabTopicsUpdate->moveToOrigin();
    $tabTopicsRenew->moveToOrigin();

    $forums_ids = array_keys($forumsUpdateTime);
    $in = implode(',', $forums_ids);
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

    $logger->info(sprintf(
        'Обработано подразделов: %d шт, раздач в них %d',
        count($forumsUpdateTime),
        $countTopicsUpdate + $countTopicsRenew
    ));
}
$logger->info("Обновление сведений о раздачах завершено за " . Timers::getExecTime('topics_update'));
