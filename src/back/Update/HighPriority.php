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
use KeepersTeam\Webtlo\Module\CloneTable;
use KeepersTeam\Webtlo\Tables\Seeders;
use KeepersTeam\Webtlo\Tables\Topics;
use KeepersTeam\Webtlo\Tables\UpdateTime;
use KeepersTeam\Webtlo\Timers;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class HighPriority
{
    private ?CloneTable $tableUpdate = null;
    private ?CloneTable $tableInsert = null;

    private const KEYS_UPDATE = [
        'id',
        'forum_id',
        'seeders',
        'status',
        'seeders_updates_today',
        'seeders_updates_days',
        'keeping_priority',
        'poster',
    ];
    private const KEYS_INSERT = [
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
    ];

    private array $topicsUpdate = [];
    private array $topicsInsert = [];
    private array $topicsDelete = [];

    /** @var int[] Хранимые подразделы */
    private array $subsections = [];

    public function __construct(
        private readonly ApiClient       $apiClient,
        private readonly DB              $db,
        private readonly Topics          $topics,
        private readonly UpdateTime      $updateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Выполнить обновление раздач с высоким приоритетом всего форума.
     */
    public function update(array $config): void
    {
        // Хранимые подразделы.
        $this->subsections = array_keys($config['subsections'] ?? []);

        // Проверяем возможность запуска обновления.
        if (!Helper::isUpdatePropertyEnabled($config, 'priority')) {
            $this->logger->notice(
                'Обновление списка раздач с высоким приоритетом отключено в настройках.'
            );

            $this->updateTime->setMarkerTime(UpdateMark::HIGH_PRIORITY->value, 0);

            // Если обновление списка высокоприоритетных раздач отключено, то удалим лишние записи в БД.
            $this->clearHighPriority();

            return;
        }

        // получаем дату предыдущего обновления
        $lastUpdated = $this->updateTime->getMarkerTime(UpdateMark::HIGH_PRIORITY->value);
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
            throw new RuntimeException('Error: Не получены данные о высокоприоритетных раздачах');
        }

        // Получение данных о сидах, в зависимости от дат обновления.
        $avgProcessor = Seeders::AverageProcessor(
            (bool)$config['avg_seeders'],
            $lastUpdated,
            $priorityResponse->updateTime
        );

        // Обрабатываем полученные раздачи, и записываем во временную таблицу.
        $this->processSubsectionTopics($priorityResponse->topics, $avgProcessor);

        // Удаляем перерегистрированные раздачи, чтобы очистить значения сидов для старой раздачи.
        $this->deleteTopics();

        // Записываем раздачи в БД.
        $topicsUpdated = $this->moveToOrigin();
        if ($topicsUpdated > 0) {
            // Записываем время обновления.
            $this->updateTime->setMarkerTime(
                UpdateMark::HIGH_PRIORITY->value,
                $priorityResponse->updateTime->getTimestamp()
            );

            $this->logger->info(
                sprintf(
                    'Список раздач с высоким приоритетом (%d шт. %s) обновлён за %2s.',
                    $topicsUpdated,
                    Helper::convertBytes($priorityResponse->totalSize, 9),
                    Timers::getExecTime('hp_topics')
                )
            );
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
                    || $topicRegistered !== (int)($previousTopic['reg_time'] ?? 0) // Изменилась дата регистрации.
                    || empty($previousTopic['name']) // Пустое название.
                    || (int)$previousTopic['poster'] === 0; // Нет автора раздачи/

                if (!$isTopicInsert) {
                    // Обновление существующей в БД раздачи.
                    $this->addTopicForUpdate(
                        [
                            $topic->id,
                            $topic->forumId,
                            $average->sumSeeders,
                            $topic->status->value,
                            $average->sumUpdates,
                            $average->daysUpdate,
                            KeepingPriority::High->value,
                            $previousTopic['poster'],
                        ]
                    );
                } else {
                    // Удаляем прошлый вариант раздачи, если он есть.
                    if (!empty($previousTopic)) {
                        $this->markTopicDelete($topic->id);
                    }

                    // Новая или обновлённая раздача.
                    $topicsInsert[$topic->id] = array_combine(
                        $this::KEYS_INSERT,
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
                $this->addTopicsForInsert($topicsInsert);

                unset($topicsInsert, $response);
            }

            // Запись раздач во временную таблицу.
            $this->fillTempTables();
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
     * @return array
     */
    private function getPrevious(array $topicsChunk): array
    {
        return $this->topics->searchPrevious(array_map(fn($tp) => $tp->id, $topicsChunk));
    }

    private function addTopicForUpdate(array $topic): void
    {
        $this->topicsUpdate[] = array_combine(self::KEYS_UPDATE, $topic);
    }

    private function addTopicsForInsert(array $topics): void
    {
        $this->topicsInsert = $topics;
    }

    private function markTopicDelete(int $topicId): void
    {
        $this->topicsDelete[] = $topicId;
    }

    private function moveToOrigin(): int
    {
        $this->initTempTables();

        // Переносим данные в основную таблицу.
        $countTopicsUpdate = $this->moveRowsInTable($this->tableUpdate);
        $countTopicsInsert = $this->moveRowsInTable($this->tableInsert);

        // Удаляем ненужные раздачи.
        $this->clearUnusedTopics();

        return $countTopicsUpdate + $countTopicsInsert;
    }

    private function moveRowsInTable(CloneTable $table): int
    {
        $count = $table->cloneCount();
        if ($count > 0) {
            $table->moveToOrigin();
        }

        return $count;
    }

    private function fillTempTables(): void
    {
        $this->initTempTables();

        if (count($this->topicsUpdate)) {
            $this->tableUpdate->cloneFill($this->topicsUpdate);
            $this->topicsUpdate = [];
        }

        if (count($this->topicsInsert)) {
            $this->tableInsert->cloneFill($this->topicsInsert);
            $this->topicsInsert = [];
        }
    }

    private function initTempTables(): void
    {
        if (null === $this->tableUpdate) {
            $this->tableUpdate = CloneTable::create(Topics::TABLE, self::KEYS_UPDATE, Topics::PRIMARY, 'hpUpdate');
        }
        if (null === $this->tableInsert) {
            $this->tableInsert = CloneTable::create(Topics::TABLE, self::KEYS_INSERT, Topics::PRIMARY, 'hpInsert');
        }
    }

    private function deleteTopics(): void
    {
        if (count($this->topicsDelete)) {
            $topics = array_unique($this->topicsDelete);

            $this->logger->debug(sprintf('Удалено перезалитых раздач %d шт.', count($topics)));
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
                    SELECT {$this->tableUpdate->primary} FROM {$this->tableUpdate->clone}
                    UNION ALL
                    SELECT {$this->tableInsert->primary} FROM {$this->tableInsert->clone}
                )
        ";
        $this->db->executeStatement($query);

        $unused = (int)$this->db->queryColumn('SELECT CHANGES()');
        if ($unused > 0) {
            $this->logger->debug(sprintf('Удалено лишних раздач %d шт.', $unused));
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
    }
}
