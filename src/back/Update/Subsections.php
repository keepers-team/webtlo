<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Update;

use DateTimeImmutable;
use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\External\Data\ApiError;
use KeepersTeam\Webtlo\External\Data\ForumTopic;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Settings;
use KeepersTeam\Webtlo\Storage\Clone\SeedersInsert;
use KeepersTeam\Webtlo\Storage\Clone\TopicsInsert;
use KeepersTeam\Webtlo\Storage\Clone\TopicsUpdate;
use KeepersTeam\Webtlo\Storage\Clone\UpdateTime;
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
        private readonly SeedersInsert   $seedersInsert,
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

        // Ограничения доступа для кандидатов в хранители.
        $user = $this->apiReport->getKeeperPermissions();

        // Выбрана опция накапливать данные о средних сидах.
        $doCollectAverageSeeds = (bool) $config['avg_seeders'];

        // Обновим каждый хранимый подраздел.
        foreach ($subsections as $forumId) {
            if ($user->isCandidate && !$user->checkSubsectionAccess(forumId: $forumId)) {
                $this->skipSubsections[] = $forumId;

                continue;
            }

            // Получаем дату предыдущего обновления подраздела.
            $forumLastUpdated = $this->updateTime->getMarkerTime(marker: $forumId);

            // Если не прошёл час с прошлого обновления - пропускаем подраздел.
            if (time() - $forumLastUpdated->getTimestamp() < 3600) {
                $this->skipSubsections[] = $forumId;

                continue;
            }

            // Получаем данные о раздачах.
            Timers::start("update_forum_$forumId");
            $topicResponse = $this->apiReport->getForumTopicsData(
                forumId         : $forumId,
                loadAverageSeeds: $doCollectAverageSeeds
            );
            if ($topicResponse instanceof ApiError) {
                $this->skipSubsections[] = $forumId;

                $this->logger->error(
                    'Не получены данные о подразделе №{forumId}',
                    ['forumId' => $forumId, 'code' => $topicResponse->code, 'text' => $topicResponse->text]
                );

                continue;
            }

            // Если дата прошлого обновления больше или равна дате в ответе API, то обновлять нечего.
            if ($forumLastUpdated >= $topicResponse->updateTime) {
                $this->skipSubsections[] = $forumId;

                continue;
            }

            // Проверим наличие раздач в подразделе.
            if ($topicResponse->totalCount === 0) {
                $this->skipSubsections[] = $forumId;

                $this->logger->warning(
                    'Отсутствуют раздачи в подразделе №{forumId}. Вероятно он не существует.',
                    ['forumId' => $forumId]
                );

                continue;
            }

            // Запоминаем время обновления подраздела.
            $this->updateTime->addMarkerUpdate(marker: $forumId, updateTime: $topicResponse->updateTime);

            $isDateChanged = self::isNewCalendarDay(
                lastUpdate   : $forumLastUpdated,
                currentUpdate: $topicResponse->updateTime
            );

            // Обрабатываем полученные раздачи, и записываем во временную таблицу.
            foreach ($topicResponse->topicsChunks as $topics) {
                $this->processSubsectionTopics(topics: $topics, isDateChanged: $isDateChanged);
            }

            $this->logger->debug(
                sprintf('Список раздач подраздела № %-4d ({count} шт.{size}) обновлён за {sec}.', $forumId),
                [
                    'count' => $topicResponse->totalCount,
                    'size'  => Helper::convertBytes($topicResponse->totalSize, 9),
                    'sec'   => Timers::getExecTime("update_forum_$forumId"),
                ],
            );
        }

        if (count($skipped = $user->getSkippedSubsections())) {
            $this->logger->notice(
                'У кандидата в хранители нет доступа к указанным подразделам. Обратитесь к куратору.',
                ['skipped' => $skipped]
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
     * @param ForumTopic[] $topics раздачи подраздела
     */
    private function processSubsectionTopics(array $topics, bool $isDateChanged): void
    {
        // Получаем прошлые данные о раздачах.
        $previousTopicsData = $this->getPreviousTopics(topics: $topics);

        // Перебираем раздачи.
        foreach ($topics as $topic) {
            // Пропускаем раздачи в невалидных статусах.
            if (!$topic->status->isValid()) {
                continue;
            }

            // Запоминаем имеющиеся данные о раздаче в локальной базе
            $previousTopic = $previousTopicsData[$topic->id] ?? [];

            // Количество дней обновлений. Если сутки сменились - увеличиваем
            $daysUpdate = $previousTopic['seeders_updates_days'] ?? 0;
            if ($isDateChanged) {
                ++$daysUpdate;
            }

            $topicRegistered = $topic->registered->getTimestamp();

            // Обновление данных или запись с нуля?
            $doTopicUpdate = $topic->hash === ($previousTopic['info_hash'] ?? null)
                && $topicRegistered === (int) ($previousTopic['reg_time'] ?? 0);

            if ($doTopicUpdate) {
                // Обновление существующей в БД раздачи.
                $this->tableUpdate->addTopic([
                    $topic->id,
                    $topic->forumId,
                    $topic->status->value,
                    $topic->todaySeeders(),
                    $topic->todayUpdates(),
                    $daysUpdate,
                    $topic->priority->value,
                    $topic->poster,
                    $topic->lastSeeded->getTimestamp(),
                ]);

                // Если сменились сутки и есть данные о СС, обновляем их в БД.
                if ($isDateChanged && $topic->averageSeeds !== null) {
                    $this->seedersInsert->addTopic(topicId: $topic->id, seeds: $topic->averageSeeds);
                }
            } else {
                // Удаляем прошлый вариант раздачи, если он есть.
                if (!empty($previousTopic)) {
                    $this->markTopicDelete($topic->id);
                }

                // Новая или обновлённая раздача.
                $this->tableInsert->addTopic([
                    $topic->id,
                    $topic->forumId,
                    $topic->status->value,
                    $topic->name,
                    $topic->hash,
                    $topic->size,
                    $topicRegistered,
                    $topic->todaySeeders(),
                    $topic->todayUpdates(),
                    $daysUpdate, // День обновления, если изменился, значит новые сутки и цифры сдвигаются
                    $topic->priority->value,
                    $topic->poster,
                    $topic->lastSeeded->getTimestamp(),
                ]);

                // Если есть данные о СС, записываем их в БД.
                if ($topic->averageSeeds !== null) {
                    $this->seedersInsert->addTopic(topicId: $topic->id, seeds: $topic->averageSeeds);
                }
            }

            unset($topic, $previousTopic, $topicRegistered);
        }

        // Запись раздач во временную таблицу.
        $this->tableUpdate->cloneFill();
        $this->tableInsert->cloneFill();
        $this->seedersInsert->cloneFill();
    }

    /**
     * Сменились ли сутки, относительно прошлого обновления сведений.
     */
    private static function isNewCalendarDay(DateTimeImmutable $lastUpdate, DateTimeImmutable $currentUpdate): bool
    {
        // Полночь дня последнего обновления сведений.
        $lastUpdated = $lastUpdate->setTime(hour: 0, minute: 0);

        // Сменились ли сутки, относительно прошлого обновления сведений.
        $diffInDays = (int) $currentUpdate->diff($lastUpdated)->format('%d');

        return $diffInDays > 0;
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
        Timers::start('writeTopicsToOrigin');
        // Переносим данные в основную таблицу.
        $countTopicsUpdate = $this->tableUpdate->writeTable();
        $countTopicsInsert = $this->tableInsert->writeTable();

        $seedersTopicsCount = $this->seedersInsert->writeTable();

        // Удаляем ненужные раздачи.
        $this->clearUnusedTopics(updatedSubsections: $updatedSubsections);

        $this->logger->debug(
            'Перенос данных из временной таблицы выполнен за {sec}',
            ['sec' => Timers::getExecTime('writeTopicsToOrigin')]
        );

        $this->logger->info('Обработано хранимых подразделов: {count} шт, уникальных раздач в них {topics} шт.', [
            'count'  => count($updatedSubsections),
            'topics' => $countTopicsUpdate + $countTopicsInsert,
        ]);

        if ($seedersTopicsCount > 0) {
            $this->logger->debug(
                'Выполнено заполнение истории средних сидов для {count} раздач.',
                ['count' => $seedersTopicsCount]
            );
        }
    }

    /**
     * @param int[] $updatedSubsections
     */
    private function clearUnusedTopics(array $updatedSubsections): void
    {
        Timers::start('clearUnusedTopics');
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
            $this->logger->debug(
                'Удалено лишних раздач {count} шт. за {sec}',
                ['count' => $unused, 'sec' => Timers::getExecTime('clearUnusedTopics')]
            );
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
