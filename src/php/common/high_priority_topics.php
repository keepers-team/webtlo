<?php

error_reporting(E_ALL);

include_once dirname(__FILE__) . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\HighPriorityTopic;
use KeepersTeam\Webtlo\Legacy\Db;
use KeepersTeam\Webtlo\Module\CloneTable;
use KeepersTeam\Webtlo\Module\LastUpdate;
use KeepersTeam\Webtlo\Module\Topics;
use KeepersTeam\Webtlo\Timers;

$app = AppContainer::create();

$logger = $app->getLogger();

// получение настроек
$cfg = $app->getLegacyConfig();

// Хранимые подразделы.
$subsections = array_keys($cfg['subsections'] ?? []);

if ($cfg['update']['priority'] == 0) {
    $logger->notice('Обновление списка раздач с высоким приоритетом отключено в настройках.');
    LastUpdate::setTime(UpdateMark::HIGH_PRIORITY->value, 0);

    // Если обновление списка высокоприоритетных раздач отключено, то удалим лишние записи в БД.
    if (count($subsections)) {
        $sub = KeysObject::create($subsections);
        Db::query_database(
            "DELETE FROM Topics WHERE keeping_priority = 2 AND Topics.forum_id NOT IN ($sub->keys)",
            $sub->values
        );
    }

    return;
}

Timers::start('hp_topics');
$logger->info('Начато обновление списка высокоприоритетных раздач...');
// получаем дату предыдущего обновления
$updateTime = LastUpdate::getTime(UpdateMark::HIGH_PRIORITY->value);
// если не прошло два часа
if (time() - $updateTime < 7200) {
    $logger->notice('Не требуется обновление списка высокоприоритетных раздач');

    return;
}

// подключаемся к api
$apiClient = $app->getApiClient();

// получаем данные о раздачах
$response = $apiClient->getTopicsHighPriority();
if ($response instanceof ApiError) {
    $logger->error(sprintf('%d %s', $response->code, $response->text));
    throw new RuntimeException('Error: Не получены данные о высокоприоритетных раздачах');
}

// Обновляемые раздачи.
$tabHighUpdate = CloneTable::create(
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
    ],
    'id',
    'HighUpdate'
);

// Новые раздачи.
$tabHighRenew = CloneTable::create(
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
    'HighRenew'
);


// время текущего и предыдущего обновления
$currentUpdateTime = $response->updateTime;

// время последнего обновления данных на api
$topicsHighPriorityUpdateTime = (int)$currentUpdateTime->format('U');
// количество раздач
$topicsHighPriorityTotalCount = 0;
// предыдущее обновление в DateTime
$previousUpdateTime = (new DateTimeImmutable())->setTimestamp($updateTime)->setTime(0, 0);
// разница в днях между обновлениями сведений
$daysDiffAdjusted = $currentUpdateTime->diff($previousUpdateTime)->format('%d');


// Убираем раздачи, из разделов, которые храним.
$topicsHighPriority = array_filter($response->topics, function($el) use ($subsections) {
    return !in_array($el->forumId, $subsections);
});

// Разбиваем список раздач по 500 шт.
/** @var HighPriorityTopic[][] $topicsChunks */
$topicsChunks = array_chunk($topicsHighPriority, 500, true);
unset($topicsHighPriority);

// Приоритетт хранения раздач.
$keepingPriority = 2;

// проходим по всем раздачам
foreach ($topicsChunks as $topicsChunk) {
    // получаем данные о раздачах за предыдущее обновление
    $selectTopics = KeysObject::create(array_map(fn($tp) => $tp->id, $topicsChunk));

    $previousTopicsData = Db::query_database(
        "
            SELECT id, seeders, reg_time, seeders_updates_today, seeders_updates_days, poster, length(name) AS lgth
            FROM Topics
            WHERE id IN ($selectTopics->keys)
        ",
        $selectTopics->values,
        true,
        PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE
    );
    unset($selectTopics);

    $db_topics_renew = $db_topics_update = [];
    // разбираем раздачи
    foreach ($topicsChunk as $topic) {
        if (!in_array($topic->status->value, Topics::VALID_STATUSES)) {
            continue;
        }

        $daysUpdate = 0;
        $sumUpdates = 1;
        $sumSeeders = $topic->seeders;
        // запоминаем имеющиеся данные о раздаче в локальной базе
        if (isset($previousTopicsData[$topic->id])) {
            $previousTopicData = $previousTopicsData[$topic->id];
        }

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

        // получить для раздачи info_hash, topic_title, poster_id, seeder_last_seen
        if (
            empty($previousTopicData)
            || $isTopicDataDelete
            || $previousTopicData['lgth'] == 0 // Пустое название
            || $previousTopicData['poster'] === 0  // Нет автора раздачи
        ) {
            $db_topics_renew[$topic->id] = array_combine(
                $tabHighRenew->keys,
                [
                    $topic->id,
                    $topic->forumId,
                    '',
                    '',
                    $sumSeeders,
                    $topic->size,
                    $topic->status->value,
                    $topicRegistered,
                    $sumUpdates,
                    $daysUpdate,
                    $keepingPriority,
                    0,
                    0,
                ]
            );
            unset($previousTopicData);
            continue;
        }

        // алгоритм нахождения среднего значения сидов
        if ($cfg['avg_seeders']) {
            $daysUpdate = $previousTopicData['seeders_updates_days'];
            // по прошествии дня
            if ($daysDiffAdjusted > 0) {
                $daysUpdate++;
            } else {
                $sumUpdates += $previousTopicData['seeders_updates_today'];
                $sumSeeders += $previousTopicData['seeders'];
            }
        }

        $db_topics_update[$topic->id] = array_combine(
            $tabHighUpdate->keys,
            [
                $topic->id,
                $topic->forumId,
                $sumSeeders,
                $topic->status->value,
                $sumUpdates,
                $daysUpdate,
                $keepingPriority,
                $previousTopicData['poster'],
            ]
        );
        unset($previousTopicData);
    }
    unset($previousTopicsData, $topicsChunk);

    // вставка данных в базу о новых раздачах
    if (count($db_topics_renew)) {
        // Получить описание новых раздач.
        $response = $apiClient->getTopicsDetails(array_keys($db_topics_renew));
        if ($response instanceof ApiError) {
            $logger->error(sprintf('%d %s', $response->code, $response->text));
            throw new RuntimeException('Error: Не получены дополнительные данные о раздачах');
        }

        foreach ($response->topics as $topic) {
            if (isset($db_topics_renew[$topic->id])) {
                $db_topics_renew[$topic->id]['info_hash']        = $topic->hash;
                $db_topics_renew[$topic->id]['name']             = $topic->title;
                $db_topics_renew[$topic->id]['poster']           = $topic->poster;
                $db_topics_renew[$topic->id]['seeder_last_seen'] = (int)$topic->lastSeeded->format('U');
            }
        }

        $tabHighRenew->cloneFill($db_topics_renew);
        unset($db_topics_renew);
    }

    // обновление данных в базе о существующих раздачах
    if (count($db_topics_update)) {
        $tabHighUpdate->cloneFill($db_topics_update);
        unset($db_topics_update);
    }
    unset($db_topics_update);
}
unset($topicsHighPriority);

// удаляем перерегистрированные раздачи
// чтобы очистить значения сидов для старой раздачи
if (isset($topicsDelete)) {
    Topics::deleteTopicsByIds($topicsDelete);
    unset($topicsDelete);
}

$countTopicsUpdate = $tabHighUpdate->cloneCount();
$countTopicsRenew  = $tabHighRenew->cloneCount();
if ($countTopicsUpdate > 0 || $countTopicsRenew > 0) {
    // переносим данные в основную таблицу
    $tabHighUpdate->moveToOrigin();
    $tabHighRenew->moveToOrigin();

    // Удалим раздачи с высоким приоритетом, которых нет во временных таблицах за исключением хранимых подразделов.
    $exclude = KeysObject::create($subsections);
    Db::query_database(
        "
            DELETE FROM Topics
            WHERE id IN (
                SELECT Topics.id
                FROM Topics
                LEFT JOIN $tabHighUpdate->clone AS thu ON Topics.id = thu.id
                LEFT JOIN $tabHighRenew->clone  AS thr ON Topics.id = thr.id
                WHERE thu.id IS NULL AND thr.id IS NULL
                    AND Topics.keeping_priority = 2
                    AND Topics.forum_id NOT IN ($exclude->keys)
            )
        ",
        $exclude->values
    );
    // Записываем время обновления.
    LastUpdate::setTime(UpdateMark::HIGH_PRIORITY->value, $topicsHighPriorityUpdateTime);

    $logger->info(
        sprintf(
            'Обновление высокоприоритетных раздач завершено за %s, обработано раздач: %d шт',
            Timers::getExecTime('hp_topics'),
            $countTopicsUpdate + $countTopicsRenew
        )
    );
}
