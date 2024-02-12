<?php

include_once dirname(__FILE__) . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\HighPriorityTopic;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Tables\Seeders;
use KeepersTeam\Webtlo\Tables\Topics;
use KeepersTeam\Webtlo\Tables\UpdateTime;
use KeepersTeam\Webtlo\Timers;
use KeepersTeam\Webtlo\Update\HighPriority;

$app = AppContainer::create('update.log');

$logger = $app->getLogger();

// получение настроек
$cfg = $app->getLegacyConfig();

// Хранимые подразделы.
$subsections = array_keys($cfg['subsections'] ?? []);

/** @var UpdateTime $tabUpdateTime */
$tabUpdateTime = $app->get(UpdateTime::class);

/** @var HighPriority $hpUpdate Раздачи с высоким приоритетом. */
$hpUpdate = $app->get(HighPriority::class);

if ((int)$cfg['update']['priority'] === 0) {
    $logger->notice('Обновление списка раздач с высоким приоритетом отключено в настройках.');
    $tabUpdateTime->setMarkerTime(UpdateMark::HIGH_PRIORITY->value, 0);

    // Если обновление списка высокоприоритетных раздач отключено, то удалим лишние записи в БД.
    $hpUpdate->clearHighPriority($subsections);

    return;
}


// получаем дату предыдущего обновления
$hpLastUpdated = $tabUpdateTime->getMarkerTime(UpdateMark::HIGH_PRIORITY->value);
// если не прошло два часа
if (time() - $hpLastUpdated->getTimestamp() < 7200) {
    $logger->notice('Не требуется обновление списка высокоприоритетных раздач.');

    return;
}

Timers::start('hp_topics');
$logger->info('Начато обновление списка высокоприоритетных раздач...');

// подключаемся к api
$apiClient = $app->getApiClient();

// получаем данные о раздачах
$hpResponse = $apiClient->getTopicsHighPriority();
if ($hpResponse instanceof ApiError) {
    $logger->error(sprintf('%d %s', $hpResponse->code, $hpResponse->text));
    throw new RuntimeException('Error: Не получены данные о высокоприоритетных раздачах');
}

/** @var Topics $tableTopics */
$tableTopics = $app->get(Topics::class);

// Получение данных о сидах, в зависимости от дат обновления.
$avgProcessor = Seeders::AverageProcessor(
    (bool)$cfg['avg_seeders'],
    $hpLastUpdated,
    $hpResponse->updateTime
);

// Убираем раздачи, из разделов, которые храним.
$topicsHighPriority = array_filter($hpResponse->topics, function($el) use ($subsections) {
    return !in_array($el->forumId, $subsections);
});

// Разбиваем список раздач по 500 шт.
/** @var HighPriorityTopic[][] $topicsChunks */
$topicsChunks = array_chunk($topicsHighPriority, 500, true);
unset($topicsHighPriority);

// Приоритет хранения раздач.
$keepingPriority = 2;

// Перебираем группы раздач.
foreach ($topicsChunks as $topicsChunk) {
    // Получаем прошлые данные о раздачах.
    $previousTopicsData = $tableTopics->searchPrevious(array_map(fn($tp) => $tp->id, $topicsChunk));

    $topicsInsert = [];
    // Перебираем раздачи.
    foreach ($topicsChunk as $topic) {
        // Пропускаем раздачи в невалидных статусах.
        if (!$tableTopics->isValidTopic($topic->status)) {
            continue;
        }

        // запоминаем имеющиеся данные о раздаче в локальной базе
        $previousTopic = $previousTopicsData[$topic->id] ?? [];

        // Алгоритм нахождения среднего значения сидов.
        $average = $avgProcessor($topic->seeders, $previousTopic);

        $topicRegistered = $topic->registered->getTimestamp();

        // Обновление данных или запись с нуля?
        $isTopicInsert = empty($previousTopic) // Нет данных о раздаче.
            || $topicRegistered !== (int)($previousTopic['reg_time'] ?? 0) // Изменилась дата регистрации.
            || empty($previousTopic['name']) // Пустое название.
            || (int)$previousTopic['poster'] === 0; // Нет автора раздачи/

        if (!$isTopicInsert) {
            // Обновление существующей в БД раздачи.
            $hpUpdate->addTopicForUpdate(
                [
                    $topic->id,
                    $topic->forumId,
                    $average->sumSeeders,
                    $topic->status->value,
                    $average->sumUpdates,
                    $average->daysUpdate,
                    $keepingPriority,
                    $previousTopic['poster'],
                ]
            );
        } else {
            // Удаляем прошлый вариант раздачи, если он есть.
            if (!empty($previousTopic)) {
                $hpUpdate->markTopicDelete($topic->id);
            }

            // Новая или обновлённая раздача.
            $topicsInsert[$topic->id] = array_combine(
                $hpUpdate::KEYS_INSERT,
                [
                    $topic->id,
                    $topic->forumId,
                    '',
                    '',
                    $average->sumSeeders,
                    $topic->size,
                    $topic->status->value,
                    $topicRegistered,
                    $average->sumUpdates,
                    $average->daysUpdate,
                    $keepingPriority,
                    0,
                    0,
                ]
            );
        }
    }
    unset($previousTopicsData, $topicsChunk);

    // Поиск нужных данных о новых раздачах.
    if (count($topicsInsert)) {
        // Получить описание новых раздач.
        $response = $apiClient->getTopicsDetails(array_keys($topicsInsert));
        if ($response instanceof ApiError) {
            $logger->error(sprintf('%d %s', $response->code, $response->text));
            throw new RuntimeException('Error: Не получены дополнительные данные о раздачах');
        }

        foreach ($response->topics as $topic) {
            if (isset($topicsInsert[$topic->id])) {
                $topicsInsert[$topic->id]['info_hash']        = $topic->hash;
                $topicsInsert[$topic->id]['name']             = $topic->title;
                $topicsInsert[$topic->id]['poster']           = $topic->poster;
                $topicsInsert[$topic->id]['seeder_last_seen'] = $topic->lastSeeded->getTimestamp();
            }
        }

        // Добавить раздачи в буффер.
        $hpUpdate->addTopicsForInsert($topicsInsert);

        unset($topicsInsert, $response);
    }

    // Запись раздач во временную таблицу.
    $hpUpdate->fillTempTables();
}

// Удаляем перерегистрированные раздачи, чтобы очистить значения сидов для старой раздачи.
$hpUpdate->deleteTopics();

// Записываем раздачи в БД.
$topicsUpdated = $hpUpdate->moveToOrigin($subsections);
if ($topicsUpdated > 0) {
    // Записываем время обновления.
    $tabUpdateTime->setMarkerTime(UpdateMark::HIGH_PRIORITY->value, $hpResponse->updateTime->getTimestamp());

    $logger->info(
        sprintf(
            'Спискок раздач с высоким приоритетом (%d шт. %s) обновлён за %2s.',
            $topicsUpdated,
            Helper::convertBytes($hpResponse->totalSize, 9),
            Timers::getExecTime('hp_topics')
        )
    );
}
