<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Update;

use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\ForumTopic;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Settings;
use KeepersTeam\Webtlo\Storage\Clone\TopicsInsert;
use KeepersTeam\Webtlo\Storage\Clone\TopicsUpdate;
use KeepersTeam\Webtlo\Storage\Clone\UpdateTime;
use KeepersTeam\Webtlo\Storage\Table\Seeders;
use KeepersTeam\Webtlo\Storage\Table\Topics;
use KeepersTeam\Webtlo\Timers;
use Psr\Log\LoggerInterface;

final class Subsections
{
    /** @var int[] */
    private array $topicsDelete = [];

    /** @var int[] */
    private array $skipSubsections = [];

    public function __construct(
        private readonly ApiReportClient $apiReport,
        private readonly Settings        $settings,
        private readonly DB              $db,
        private readonly Topics          $topics,
        private readonly TopicsInsert    $tableInsert,
        private readonly TopicsUpdate    $tableUpdate,
        private readonly UpdateTime      $updateTime,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Выполнить обновление раздач в хранимых подразделах.
     */
    public function update(): void
    {
        // Получаем параметры.
        $config = $this->settings->get();

        // Проверяем наличие хранимых подразделов.
        $subsections = array_keys($config['subsections'] ?? []);
        if (!count($subsections)) {
            $this->logger->warning('Выполнить обновление сведений невозможно. Отсутствуют хранимые подразделы.');

            return;
        }

        Timers::start('topics_update');
        $this->logger->info('Начато обновление сведений о раздачах в хранимых подразделах...');

        /** @var int[] $subsections */
        $subsections = array_map('intval', $subsections);
        sort($subsections);

        // Обновим каждый хранимый подраздел.
        foreach ($subsections as $forumId) {
            // Получаем дату предыдущего обновления подраздела.
            $forumLastUpdated = $this->updateTime->getMarkerTime(marker: $forumId);

            // Если не прошёл час с прошлого обновления - пропускаем подраздел.
            if (time() - $forumLastUpdated->getTimestamp() < 3600) {
                $this->skipSubsections[] = $forumId;

                continue;
            }

            // Получаем данные о раздачах.
            Timers::start("update_forum_$forumId");
            $topicResponse = $this->apiReport->getForumTopicsData(forumId: $forumId);
            if ($topicResponse instanceof ApiError) {
                $this->skipSubsections[] = $forumId;

                $this->logger->error(
                    'Не получены данные о подразделе №{forumId}',
                    ['forumId' => $forumId, 'code' => $topicResponse->code, 'text' => $topicResponse->text]
                );

                continue;
            }

            // Если дата прошлого обновления совпадает с датой в ответе API, то обновлять нечего.
            if ($forumLastUpdated === $topicResponse->updateTime) {
                $this->skipSubsections[] = $forumId;

                continue;
            }

            // Запоминаем время обновления подраздела.
            $this->updateTime->addMarkerUpdate(marker: $forumId, updateTime: $topicResponse->updateTime);

            // Получение данных о сидах, в зависимости от дат обновления.
            $avgProcessor = Seeders::AverageProcessor(
                calcAverage: (bool) $config['avg_seeders'],
                lastUpdated: $forumLastUpdated,
                updateTime : $topicResponse->updateTime
            );

            // Обрабатываем полученные раздачи, и записываем во временную таблицу.
            $this->processSubsectionTopics(topics: $topicResponse->topics, avgProcessor: $avgProcessor);

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
        $this->moveUpdatedTopics(subsections: $subsections);

        $this->logger->info(
            'Завершено обновление сведений о раздачах в хранимых подразделах за {sec}.',
            ['sec' => Timers::getExecTime('topics_update')]
        );
    }

    /**
     * Обработать раздачи подраздела.
     *
     * @param ForumTopic[] $topics       раздачи подраздела
     * @param callable     $avgProcessor расчёт средних сидов
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
            $previousTopicsData = $this->getPreviousTopics(topics: $topicsChunk);

            // Перебираем раздачи.
            foreach ($topicsChunk as $topic) {
                // Пропускаем раздачи в невалидных статусах.
                if (!$topic->status->isValid()) {
                    continue;
                }

                // запоминаем имеющиеся данные о раздаче в локальной базе
                $previousTopic = $previousTopicsData[$topic->id] ?? [];

                // Алгоритм нахождения среднего значения сидов.
                $average = $avgProcessor($topic->seeders, $previousTopic);

                $topicRegistered = $topic->registered->getTimestamp();

                // Обновление данных или запись с нуля?
                $doTopicUpdate = $topic->hash === ($previousTopic['info_hash'] ?? null)
                    && $topicRegistered === (int) ($previousTopic['reg_time'] ?? 0);

                if ($doTopicUpdate) {
                    // Обновление существующей в БД раздачи.
                    $this->tableUpdate->addTopic([
                        $topic->id,
                        $topic->forumId,
                        $average->sumSeeders,
                        $topic->status->value,
                        $average->sumUpdates,
                        $average->daysUpdate,
                        $topic->priority->value,
                        $topic->poster,
                        $topic->lastSeeded->getTimestamp(),
                    ]);
                } else {
                    // Удаляем прошлый вариант раздачи, если он есть.
                    if (!empty($previousTopic)) {
                        $this->markTopicDelete($topic->id);
                    }

                    // Новая или обновлённая раздача.
                    $this->tableInsert->addTopic([
                        $topic->id,
                        $topic->forumId,
                        $topic->name,
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
                    ]);
                }

                unset($topic, $previousTopic, $topicRegistered);
            }
            unset($topicsChunk, $previousTopicsData);

            // Запись раздач во временную таблицу.
            $this->tableUpdate->cloneFill();
            $this->tableInsert->cloneFill();
        }
    }

    /**
     * @param ForumTopic[] $topics
     *
     * @return array<int, array<string, int|string>>
     */
    private function getPreviousTopics(array $topics): array
    {
        return $this->topics->searchPrevious(array_map(static fn($tp) => $tp->id, $topics));
    }

    /**
     * Пропущенные подразделы пишем в лог.
     */
    private function checkSkippedSubsections(): void
    {
        if (count($this->skipSubsections)) {
            $this->logger->notice(
                'Обновление списков раздач не требуется для подразделов №№ {forums}',
                ['forums' => implode(', ', $this->skipSubsections)]
            );
        }
    }

    /**
     * Переносим обработанные сведения из временных таблицы в БД, фиксируем обновление.
     *
     * @param int[] $subsections
     */
    private function moveUpdatedTopics(array $subsections): void
    {
        $updatedSubsections = array_diff($subsections, $this->skipSubsections);
        if (count($updatedSubsections)) {
            // Удаляем перерегистрированные раздачи, чтобы очистить значения сидов для старой раздачи.
            $this->deleteTopics();

            // Записываем раздачи в БД.
            $this->writeTopicsToOrigin(updatedSubsections: $updatedSubsections);

            // Записываем время обновления подразделов.
            $this->updateTime->addMarkerUpdate(marker: UpdateMark::SUBSECTIONS);
            $this->updateTime->moveToOrigin();
        }
    }

    /**
     * @param int[] $updatedSubsections
     */
    private function writeTopicsToOrigin(array $updatedSubsections): void
    {
        // Переносим данные в основную таблицу.
        $countTopicsUpdate = $this->tableUpdate->writeTable();
        $countTopicsInsert = $this->tableInsert->writeTable();

        // Удаляем ненужные раздачи.
        $this->clearUnusedTopics(updatedSubsections: $updatedSubsections);

        $this->logger->info('Обработано хранимых подразделов: {count} шт, уникальных раздач в них {topics} шт.', [
            'count'  => count($updatedSubsections),
            'topics' => $countTopicsUpdate + $countTopicsInsert,
        ]);
    }

    /**
     * @param int[] $updatedSubsections
     */
    private function clearUnusedTopics(array $updatedSubsections): void
    {
        $in = implode(',', $updatedSubsections);

        $query = "
            DELETE
            FROM Topics
            WHERE forum_id IN ($in)
                AND id NOT IN (
                    {$this->tableInsert->querySelectPrimaryClone()}
                    UNION ALL
                    {$this->tableUpdate->querySelectPrimaryClone()}
                )
        ";
        $this->db->executeStatement($query);

        $unused = $this->db->queryChanges();
        if ($unused > 0) {
            $this->logger->debug("Удалено лишних раздач $unused шт.");
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

            $this->logger->debug('Удалено перезалитых раздач {count} шт.', ['count' => count($topics)]);
            $this->topics->deleteTopicsByIds($topics);
        }
    }
}
