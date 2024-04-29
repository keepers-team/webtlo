<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Update;

use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\ForumTopic;
use KeepersTeam\Webtlo\External\ApiClient;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Module\CloneTable;
use KeepersTeam\Webtlo\Tables\KeepersSeeders;
use KeepersTeam\Webtlo\Tables\Seeders;
use KeepersTeam\Webtlo\Tables\Topics;
use KeepersTeam\Webtlo\Tables\UpdateTime;
use KeepersTeam\Webtlo\Timers;
use Psr\Log\LoggerInterface;

final class Subsections
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
        'seeder_last_seen',
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

    private array $skipSubsections = [];

    public function __construct(
        private readonly ApiClient       $apiClient,
        private readonly DB              $db,
        private readonly Topics          $topics,
        private readonly KeepersSeeders  $keepersSeeders,
        private readonly UpdateTime      $updateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Выполнить обновление раздач в хранимых подразделах.
     */
    public function update(array $config, bool $schedule = false): void
    {
        // Проверяем возможность запуска обновления.
        if (!$schedule && !Helper::isScheduleActionEnabled($config, 'update')) {
            $this->logger->notice(
                'Автоматическое обновление сведений о раздачах в хранимых подразделах отключено в настройках.'
            );

            return;
        }

        // Проверяем наличие хранимых подразделов.
        $subsections = array_keys($config['subsections'] ?? []);
        if (!count($subsections)) {
            $this->logger->warning('Выполнить обновление сведений невозможно. Отсутствуют хранимые подразделы.');

            return;
        }

        Timers::start('topics_update');
        $this->logger->info('Начато обновление сведений о раздачах в хранимых подразделах...');

        // Получаем список хранителей.
        if (!$this->getKeepersList()) {
            return;
        }

        $tabUpdateTime = $this->updateTime;

        // Обновим каждый хранимый подраздел.
        sort($subsections);
        foreach ($subsections as $forumId) {
            $forumId = (int)$forumId;

            // Получаем дату предыдущего обновления подраздела.
            $forumLastUpdated = $tabUpdateTime->getMarkerTime($forumId);

            // Если не прошёл час с прошлого обновления - пропускаем подраздел.
            if (time() - $forumLastUpdated->getTimestamp() < 3600) {
                $this->skipSubsections[] = $forumId;
                continue;
            }

            // Получаем данные о раздачах.
            Timers::start("update_forum_$forumId");
            $topicResponse = $this->apiClient->getForumTopicsData($forumId);
            if ($topicResponse instanceof ApiError) {
                $this->skipSubsections[] = $forumId;
                $this->logger->error(
                    sprintf(
                        'Не получены данные о подразделе №%d (%d %s)',
                        $forumId,
                        $topicResponse->code,
                        $topicResponse->text
                    )
                );
                continue;
            }

            // Запоминаем время обновления подраздела.
            $tabUpdateTime->addMarkerUpdate($forumId, $topicResponse->updateTime);

            // Получение данных о сидах, в зависимости от дат обновления.
            $avgProcessor = Seeders::AverageProcessor(
                (bool)$config['avg_seeders'],
                $forumLastUpdated,
                $topicResponse->updateTime
            );

            // Обрабатываем полученные раздачи, и записываем во временную таблицу.
            $this->processSubsectionTopics($topicResponse->topics, $avgProcessor);

            $this->logger->debug(
                sprintf(
                    'Список раздач подраздела № %-4d (%d шт. %s) обновлён за %2s.',
                    $forumId,
                    count($topicResponse->topics),
                    Helper::convertBytes($topicResponse->totalSize, 9),
                    Timers::getExecTime("update_forum_$forumId")
                )
            );
        }

        $this->checkSkippedSubsections();

        // Успешно обновлённые подразделы.
        $this->moveUpdatedTopics($subsections);

        $this->logger->info(
            sprintf(
                'Завершено обновление сведений о раздачах в хранимых подразделах за %s.',
                Timers::getExecTime('topics_update')
            )
        );
    }

    /**
     * Загрузить список всех хранителей.
     */
    private function getKeepersList(): bool
    {
        $response = $this->apiClient->getKeepersList();
        if ($response instanceof ApiError) {
            $this->logger->error(
                sprintf('Не получены данные о хранителях (%d: %s).', $response->code, $response->text)
            );

            return false;
        }
        $this->keepersSeeders->addKeepersInfo($response->keepers);

        return true;
    }

    /**
     * Обработать раздачи подраздела.
     *
     * @param ForumTopic[] $topics       Раздачи подраздела.
     * @param callable     $avgProcessor Расчёт средних сидов.
     */
    private function processSubsectionTopics(array $topics, callable $avgProcessor): void
    {
        /**
         * Разбиваем result по 500 раздач.
         *
         * @var ForumTopic[][] $topicsChunks
         */
        $topicsChunks = array_chunk($topics, 500);

        foreach ($topicsChunks as $topicsChunk) {
            // Получаем прошлые данные о раздачах.
            $previousTopicsData = $this->getPreviousTopics($topicsChunk);

            // Перебираем раздачи.
            foreach ($topicsChunk as $topic) {
                // Пропускаем раздачи в невалидных статусах.
                if (!$this->topics->isValidTopic($topic->status)) {
                    continue;
                }

                // Записываем хранителей раздачи.
                if (!empty($topic->keepers)) {
                    $this->keepersSeeders->addKeptTopic($topic->id, $topic->keepers);
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
                    $this->addTopicForUpdate(
                        [
                            $topic->id,
                            $topic->forumId,
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
                        $this->markTopicDelete($topic->id);
                    }

                    // Новая или обновлённая раздача.
                    $this->addTopicForInsert(
                        [
                            $topic->id,
                            $topic->forumId,
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
            $this->keepersSeeders->fillTempTable();

            // Запись раздач во временную таблицу.
            $this->fillTempTables();
        }
    }

    /**
     * @param ForumTopic[] $topics
     * @return array
     */
    private function getPreviousTopics(array $topics): array
    {
        return $this->topics->searchPrevious(array_map(fn($tp) => $tp->id, $topics));
    }

    private function addTopicForUpdate(array $topic): void
    {
        $this->topicsUpdate[] = array_combine(self::KEYS_UPDATE, $topic);
    }

    private function addTopicForInsert(array $topic): void
    {
        $this->topicsInsert[] = array_combine(self::KEYS_INSERT, $topic);
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
            $this->tableUpdate = CloneTable::create(Topics::TABLE, self::KEYS_UPDATE, Topics::PRIMARY, 'Update');
        }
        if (null === $this->tableInsert) {
            $this->tableInsert = CloneTable::create(Topics::TABLE, self::KEYS_INSERT, Topics::PRIMARY, 'Insert');
        }
    }

    /**
     * Пропущенные подразделы пишем в лог.
     */
    private function checkSkippedSubsections(): void
    {
        if (count($this->skipSubsections)) {
            $this->logger->notice(
                sprintf(
                    'Обновление списков раздач не требуется для подразделов №№ %s',
                    implode(', ', $this->skipSubsections)
                )
            );
        }
    }

    /**
     * Переносим обработанные сведения из временных таблицы в БД, фиксируем обновление.
     */
    private function moveUpdatedTopics(array $subsections): void
    {
        $updatedSubsections = array_diff($subsections, $this->skipSubsections);
        if (count($updatedSubsections)) {
            // Удаляем перерегистрированные раздачи, чтобы очистить значения сидов для старой раздачи.
            $this->deleteTopics();

            // Записываем раздачи в БД.
            $this->writeTopicsToOrigin($updatedSubsections);

            // Записываем данные о сидах-хранителях в БД.
            $this->keepersSeeders->moveToOrigin();

            // Записываем время обновления подразделов.
            $tabUpdateTime = $this->updateTime;
            $tabUpdateTime->addMarkerUpdate(UpdateMark::SUBSECTIONS->value);
            $tabUpdateTime->fillTable();
        }
    }

    private function writeTopicsToOrigin(array $updatedSubsections): void
    {
        $this->initTempTables();

        // Переносим данные в основную таблицу.
        $countTopicsUpdate = $this->writeTableTopics($this->tableUpdate);
        $countTopicsInsert = $this->writeTableTopics($this->tableInsert);

        // Удаляем ненужные раздачи.
        $this->clearUnusedTopics($updatedSubsections);

        $this->logger->info(
            sprintf(
                'Обработано хранимых подразделов: %d шт, уникальных раздач в них %d шт.',
                count($updatedSubsections),
                $countTopicsUpdate + $countTopicsInsert
            )
        );
    }

    private function writeTableTopics(CloneTable $table): int
    {
        $count = $table->cloneCount();
        if ($count > 0) {
            $table->moveToOrigin();
        }

        return $count;
    }

    private function clearUnusedTopics(array $updatedSubsections): void
    {
        $in = implode(',', $updatedSubsections);

        $query = "
            DELETE
            FROM Topics
            WHERE forum_id IN ($in)
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

    private function markTopicDelete(int $topicId): void
    {
        $this->topicsDelete[] = $topicId;
    }

    private function deleteTopics(): void
    {
        if (count($this->topicsDelete)) {
            $topics = array_unique($this->topicsDelete);

            $this->logger->debug(sprintf('Удалено перезалитых раздач %d шт.', count($topics)));
            $this->topics->deleteTopicsByIds($topics);
        }
    }
}
