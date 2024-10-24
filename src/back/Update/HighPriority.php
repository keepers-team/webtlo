<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Update;

use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\HighPriorityTopic;
use KeepersTeam\Webtlo\External\Api\V1\KeepingPriority;
use KeepersTeam\Webtlo\External\ApiClient;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Storage\Clone\HighPriorityInsert;
use KeepersTeam\Webtlo\Storage\Clone\HighPriorityUpdate;
use KeepersTeam\Webtlo\Tables\Seeders;
use KeepersTeam\Webtlo\Tables\Topics;
use KeepersTeam\Webtlo\Tables\UpdateTime;
use KeepersTeam\Webtlo\Timers;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class HighPriority
{
    /** @var int[] */
    private array $topicsDelete = [];

    /** @var int[] Хранимые подразделы */
    private array $subsections = [];

    public function __construct(
        private readonly ApiClient          $apiClient,
        private readonly DB                 $db,
        private readonly Topics             $topics,
        private readonly HighPriorityInsert $cloneInsert,
        private readonly HighPriorityUpdate $cloneUpdate,
        private readonly UpdateTime         $updateTime,
        private readonly LoggerInterface    $logger
    ) {}

    /**
     * Выполнить обновление раздач с высоким приоритетом всего форума.
     *
     * @param array<string, mixed> $config
     */
    public function update(array $config): void
    {
        // Хранимые подразделы.
        $this->subsections = array_map(
            'intval',
            array_keys($config['subsections'] ?? [])
        );

        // Проверяем возможность запуска обновления.
        if (!Helper::isUpdatePropertyEnabled($config, 'priority')) {
            $this->logger->notice(
                'Обновление списка раздач с высоким приоритетом отключено в настройках.'
            );

            $this->updateTime->setMarkerTime(marker: UpdateMark::HIGH_PRIORITY, updateTime: 0);

            // Если обновление списка высокоприоритетных раздач отключено, то удалим лишние записи в БД.
            $this->clearHighPriority();

            return;
        }

        // получаем дату предыдущего обновления
        $lastUpdated = $this->updateTime->getMarkerTime(marker: UpdateMark::HIGH_PRIORITY);
        // если не прошло два часа
        if (time() - $lastUpdated->getTimestamp() < 7200) {
            $this->logger->notice('Не требуется обновление списка высокоприоритетных раздач.');

            return;
        }

        Timers::start('hp_topics');
        $this->logger->info('Начато обновление списка высокоприоритетных раздач...');

        // получаем данные о раздачах
        $priorityResponse = $this->apiClient->getTopicsHighPriority();
        if ($priorityResponse instanceof ApiError) {
            $this->logger->error(sprintf('%d %s', $priorityResponse->code, $priorityResponse->text));

            throw new RuntimeException('Не получены данные о высокоприоритетных раздачах!');
        }

        // Получение данных о сидах, в зависимости от дат обновления.
        $avgProcessor = Seeders::AverageProcessor(
            (bool) $config['avg_seeders'],
            $lastUpdated,
            $priorityResponse->updateTime
        );

        // Обрабатываем полученные раздачи, и записываем во временную таблицу.
        $this->processSubsectionTopics(topics: $priorityResponse->topics, avgProcessor: $avgProcessor);

        // Записываем раздачи в БД.
        $topicsUpdated = $this->moveUpdatedTopics();
        if ($topicsUpdated > 0) {
            // Записываем время обновления.
            $this->updateTime->setMarkerTime(
                marker    : UpdateMark::HIGH_PRIORITY,
                updateTime: $priorityResponse->updateTime->getTimestamp()
            );

            $this->logger->info('Список раздач с высоким приоритетом ({count} шт. {bytes}) обновлён за {sec}.', [
                'count' => $topicsUpdated,
                'bytes' => Helper::convertBytes($priorityResponse->totalSize, 9),
                'sec'   => Timers::getExecTime('hp_topics'),
            ]);
        }
    }

    /**
     * Обработать все раздачи.
     *
     * @param HighPriorityTopic[] $topics       Раздачи.
     * @param callable            $avgProcessor Расчёт средних сидов.
     */
    private function processSubsectionTopics(array $topics, callable $avgProcessor): void
    {
        $topics = $this->chunkTopics($topics);

        // Перебираем группы раздач.
        foreach ($topics as $topicsChunk) {
            // Получаем прошлые данные о раздачах.
            $previousTopicsData = $this->getPrevious($topicsChunk);

            $topicsInsert = [];
            // Перебираем раздачи.
            foreach ($topicsChunk as $topic) {
                // Пропускаем раздачи в невалидных статусах.
                if (!$this->topics->isValidTopic($topic->status)) {
                    continue;
                }

                // Запоминаем имеющиеся данные о раздаче в локальной базе.
                $previousTopic = $previousTopicsData[$topic->id] ?? [];

                // Алгоритм нахождения среднего значения сидов.
                $average = $avgProcessor($topic->seeders, $previousTopic);

                $topicRegistered = $topic->registered->getTimestamp();

                // Обновление данных или запись с нуля?
                $isTopicInsert = empty($previousTopic) // Нет данных о раздаче.
                    || $topicRegistered !== (int) ($previousTopic['reg_time'] ?? 0) // Изменилась дата регистрации.
                    || empty($previousTopic['name']) // Пустое название.
                    || (int) $previousTopic['poster'] === 0; // Нет автора раздачи/

                if (!$isTopicInsert) {
                    // Обновление существующей в БД раздачи.
                    $this->cloneUpdate->addTopic([
                        $topic->id,
                        $topic->forumId,
                        (int) $average->sumSeeders,
                        $topic->status->value,
                        (int) $average->sumUpdates,
                        (int) $average->daysUpdate,
                        KeepingPriority::High->value,
                        (int) $previousTopic['poster'],
                    ]);
                } else {
                    // Удаляем прошлый вариант раздачи, если он есть.
                    if (!empty($previousTopic)) {
                        $this->markTopicDelete($topic->id);
                    }

                    // Новая или обновлённая раздача.
                    $topicsInsert[$topic->id] = array_combine(
                        $this->cloneInsert->getTableKeys(),
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
                            KeepingPriority::High->value,
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
                $response = $this->apiClient->getTopicsDetails(array_keys($topicsInsert));
                if ($response instanceof ApiError) {
                    $this->logger->error(sprintf('%d %s', $response->code, $response->text));
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
                $this->cloneInsert->addTopics($topicsInsert);

                unset($response);
            }
            unset($topicsInsert);

            // Запись раздач во временную таблицу.
            $this->cloneInsert->cloneFill();
            $this->cloneUpdate->cloneFill();
        }
    }

    /**
     * @param HighPriorityTopic[] $topics
     * @return HighPriorityTopic[][]
     */
    private function chunkTopics(array $topics): array
    {
        $subsections = $this->subsections;
        // Убираем раздачи, из разделов, которые храним.
        $topics = array_filter($topics, function($el) use ($subsections) {
            return !in_array($el->forumId, $subsections);
        });

        // Разбиваем список раздач по 500 шт.
        /** @var HighPriorityTopic[][] $topicsChunks */
        $topicsChunks = array_chunk($topics, 500);

        return $topicsChunks;
    }

    /**
     * @param HighPriorityTopic[] $topicsChunk
     * @return array<int, array<string, int|string>>
     */
    private function getPrevious(array $topicsChunk): array
    {
        return $this->topics->searchPrevious(array_map(fn($tp) => $tp->id, $topicsChunk));
    }

    private function markTopicDelete(int $topicId): void
    {
        $this->topicsDelete[] = $topicId;
    }

    private function moveUpdatedTopics(): int
    {
        // Удаляем перерегистрированные раздачи, чтобы очистить значения сидов для старой раздачи.
        $this->deleteTopics();

        // Переносим данные в основную таблицу.
        $countTopicsInsert = $this->cloneInsert->writeTable();
        $countTopicsUpdate = $this->cloneUpdate->writeTable();

        // Удаляем ненужные раздачи.
        $this->clearUnusedTopics();

        return $countTopicsUpdate + $countTopicsInsert;
    }

    private function deleteTopics(): void
    {
        if (count($this->topicsDelete)) {
            $topics = array_unique($this->topicsDelete);

            $this->logger->debug('Удалено перезалитых раздач {count} шт.', ['count' => count($topics)]);
            $this->topics->deleteTopicsByIds($topics);
        }
    }

    private function clearUnusedTopics(): void
    {
        $in = implode(',', $this->subsections);

        $query = "
            DELETE
            FROM Topics
            WHERE forum_id NOT IN ($in)
                AND keeping_priority = 2
                AND id NOT IN (
                    {$this->cloneInsert->querySelectPrimaryClone()}
                    UNION ALL
                    {$this->cloneUpdate->querySelectPrimaryClone()}
                )
        ";
        $this->db->executeStatement($query);

        $changes = $this->db->queryChanges();
        if ($changes > 0) {
            $this->logger->debug('Удалено лишних раздач {count} шт.', ['count' => $changes]);
        }
    }

    private function clearHighPriority(): void
    {
        if (!count($this->subsections)) {
            return;
        }

        $in = implode(',', $this->subsections);

        $this->db->executeStatement(
            "DELETE FROM Topics WHERE keeping_priority = 2 AND Topics.forum_id NOT IN ($in)"
        );

        $changes = $this->db->queryChanges();
        if ($changes > 0) {
            $this->logger->debug('Удалено ненужных высокоприоритетных раздач {count} шт.', ['count' => $changes]);
        }
    }
}
