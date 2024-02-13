<?php

include_once dirname(__FILE__) . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\ForumTopic;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Tables\Seeders;
use KeepersTeam\Webtlo\Tables\Topics;
use KeepersTeam\Webtlo\Tables\UpdateTime;
use KeepersTeam\Webtlo\Timers;
use KeepersTeam\Webtlo\Tables\KeepersSeeders;
use KeepersTeam\Webtlo\Update\Subsections;

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

$logger = $app->getLogger();

$subsections = array_keys($cfg['subsections'] ?? []);

if (!count($subsections)) {
    throw new RuntimeException('Выполнить обновление сведений невозможно. Отсутствуют хранимые подразделы.');
}

// Подключаемся к Api.
$apiClient = $app->getApiClient();

Timers::start('topics_update');
$logger->info('Начато обновление сведений о раздачах в хранимых подразделах...');

$skipUpdateForums = [];

// Загружаем список всех хранителей.
$response = $apiClient->getKeepersList();
if ($response instanceof ApiError) {
    $logger->error(sprintf('%d %s', $response->code, $response->text));
    throw new RuntimeException('Error: Не получены данные о хранителях.');
}

/** @var UpdateTime $tabUpdateTime */
$tabUpdateTime = $app->get(UpdateTime::class);

/** @var KeepersSeeders $tabKeepers Сиды-Хранители раздач. */
$tabKeepers = $app->get(KeepersSeeders::class);
$tabKeepers->addKeepersInfo($response->keepers);

/** @var Topics $tableTopics */
$tableTopics = $app->get(Topics::class);

/** @var Subsections $subUpdate Подразделы. */
$subUpdate = $app->get(Subsections::class);

// обновим каждый хранимый подраздел
sort($subsections);
foreach ($subsections as $forum_id) {
    $forum_id = (int)$forum_id;

    // Получаем дату предыдущего обновления подраздела.
    $forumLastUpdated = $tabUpdateTime->getMarkerTime($forum_id);

    // Если не прошёл час с прошлого обновления - пропускаем подраздел.
    if (time() - $forumLastUpdated->getTimestamp() < 3600) {
        $skipUpdateForums[] = $forum_id;
        continue;
    }

    // Получаем данные о раздачах.
    Timers::start("update_forum_$forum_id");
    $topicResponse = $apiClient->getForumTopicsData($forum_id);
    if ($topicResponse instanceof ApiError) {
        $skipUpdateForums[] = $forum_id;
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

    // запоминаем время обновления каждого подраздела
    $tabUpdateTime->addMarkerUpdate($forum_id, $topicResponse->updateTime);

    // Получение данных о сидах, в зависимости от дат обновления.
    $avgProcessor = Seeders::AverageProcessor(
        (bool)$cfg['avg_seeders'],
        $forumLastUpdated,
        $topicResponse->updateTime
    );

    /**
     * Разбиваем result по 500 раздач.
     *
     * @var ForumTopic[][] $topicsChunks
     */
    $topicsChunks = array_chunk($topicResponse->topics, 500);

    foreach ($topicsChunks as $topicsChunk) {
        // Получаем прошлые данные о раздачах.
        $previousTopicsData = $tableTopics->searchPrevious(array_map(fn($tp) => $tp->id, $topicsChunk));

        // Перебираем раздачи.
        foreach ($topicsChunk as $topic) {
            // Пропускаем раздачи в невалидных статусах.
            if (!$tableTopics->isValidTopic($topic->status)) {
                continue;
            }

            // Записываем хранителей раздачи.
            if (!empty($topic->keepers)) {
                $tabKeepers->addKeptTopic($topic->id, $topic->keepers);
            }

            // запоминаем имеющиеся данные о раздаче в локальной базе
            $previousTopic = $previousTopicsData[$topic->id] ?? [];

            // Алгоритм нахождения среднего значения сидов.
            $average = $avgProcessor($topic->seeders, $previousTopic);

            $topicRegistered = $topic->registered->getTimestamp();

            // Обновление данных или запись с нуля?
            $isTopicUpdate = $topicRegistered === (int)($previousTopic['reg_time'] ?? 0);

            if ($isTopicUpdate) {
                // Обновление существующей в БД раздачи.
                $subUpdate->addTopicForUpdate(
                    [
                        $topic->id,
                        $forum_id,
                        $average->sumSeeders,
                        $topic->status->value,
                        $average->sumUpdates,
                        $average->daysUpdate,
                        $topic->priority->value,
                        $topic->poster,
                        $topic->lastSeeded->getTimestamp(),
                    ]
                );
            } else {
                // Удаляем прошлый вариант раздачи, если он есть.
                if (!empty($previousTopic)) {
                    $subUpdate->markTopicDelete($topic->id);
                }

                // Новая или обновлённая раздача.
                $subUpdate->addTopicForInsert(
                    [
                        $topic->id,
                        $forum_id,
                        '',
                        $topic->hash,
                        $topic->seeders,
                        $topic->size,
                        $topic->status->value,
                        $topicRegistered,
                        $average->sumUpdates,
                        $average->daysUpdate,
                        $topic->priority->value,
                        $topic->poster,
                        $topic->lastSeeded->getTimestamp(),
                    ]
                );
            }

            unset($topic, $previousTopic, $topicRegistered);
        }
        unset($topicsChunk, $previousTopicsData);

        // Запись сидов-хранителей во временную таблицу.
        $tabKeepers->fillTempTable();

        // Запись раздач во временную таблицу.
        $subUpdate->fillTempTables();
    }

    $logger->debug(
        sprintf(
            'Спискок раздач подраздела № %-4d (%d шт. %s) обновлён за %2s.',
            $forum_id,
            count($topicResponse->topics),
            Helper::convertBytes($topicResponse->totalSize, 9),
            Timers::getExecTime("update_forum_$forum_id")
        )
    );
}

if (count($skipUpdateForums)) {
    $logger->notice(
        sprintf("Обновление списков раздач не требуется для подразделов №№ %s", implode(', ', $skipUpdateForums))
    );
}

// Успешно обновлённые подразделы.
$updatedSubsections = array_diff($subsections, $skipUpdateForums);
if (count($updatedSubsections)) {
    // Удаляем перерегистрированные раздачи, чтобы очистить значения сидов для старой раздачи.
    $subUpdate->deleteTopics();

    // Записываем раздачи в БД.
    $subUpdate->moveToOrigin($updatedSubsections);

    // Записываем данные о сидах-хранителях в БД.
    $tabKeepers->moveToOrigin();

    // Записываем время обновления подразделов.
    $tabUpdateTime->addMarkerUpdate(UpdateMark::SUBSECTIONS->value);
    $tabUpdateTime->fillTable();
}

$logger->info(
    sprintf(
        'Завершено обновление сведений о раздачах в хранимых подразделах за %s',
        Timers::getExecTime('topics_update')
    )
);
