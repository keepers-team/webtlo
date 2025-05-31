<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Update;

use KeepersTeam\Webtlo\Config\AverageSeeds;
use KeepersTeam\Webtlo\Config\SubForums;
use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\External\Data\ApiError;
use KeepersTeam\Webtlo\External\Data\ForumTopic;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Storage\Clone\SeedersInsert;
use KeepersTeam\Webtlo\Storage\Clone\TopicsInsert;
use KeepersTeam\Webtlo\Storage\Clone\TopicsUpdate;
use KeepersTeam\Webtlo\Storage\Clone\UpdateTime;
use KeepersTeam\Webtlo\Storage\Table\Topics;
use KeepersTeam\Webtlo\Timers;
use Psr\Log\LoggerInterface;

/**
 * Обновляем списки раздач (topic) в каждом хранимом подразделе (subsection).
 *
 * "Подраздел" (subsection) форума является частью большего "форума" (forum).
 * Однако по историческим причинам, эти два понятия смешиваются.
 *
 * Forum, forumId, subforumId, subsection, subsectionId - это всё одно и тоже в рамках текущего проекта.
 * TODO стандартизировать используемые названия переменных в subforumId, как в API отчётов.
 *
 * AverageSeeds - история средних сидов.
 * Это два массива данных из 31 элемента каждый:
 *  - count: int<1, 24> - количество обновлений в день (24 раза в день максимум), т.е. [1,24,24...]
 *  - sum: int[] - сумма сидов из всех обновлений за день, т.е. [4,83,136...]
 *
 * Первое число каждого из массивов - это цифры за сегодня.
 * Эти два значения записываются в БД ТЛО в таблицу Topics, где count0 => seeders_updates_today и sum0 => seeders
 *
 * Остальные значения - это вчера, позавчера и т.д. до (-30 дней от сегодня).
 * Эти значения записываются в таблицу в Seeders, где countN => qN (quantity_update) и sumN => dN (day_sum).
 *
 *  Запись в БД см. KeepersTeam\Webtlo\Storage\Clone\SeedersInsert
 *
 * Таким образом, вычислить значение средних сидов за 14 дней можно по формуле:
 * - (sum0 + ... + sum13) / (count0 + ... count13)
 *
 * Что в рамках БД ТЛО превращается в:
 * - (Topics.seeders + Seeders.d0 ... + Seeders.d12) / (Topics.seeders_updates_today + Seeders.q0 + ... Seeders.q12)
 *
 * Поиск в БД см. KeepersTeam\Webtlo\TopicList\Validate::prepareAverageSeedFilter
 */
final class Subsections
{
    /** @var int[] */
    private array $topicsDelete = [];

    /** @var int[] */
    private array $skipSubsections = [];

    public function __construct(
        private readonly ApiReportClient $apiReport,
        private readonly AverageSeeds    $averageSeeds,
        private readonly SubForums       $subForums,
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
        // Проверяем наличие хранимых подразделов.
        $subForums = $this->subForums->ids;
        if (!count($subForums)) {
            $this->logger->warning('Выполнить обновление сведений невозможно. Отсутствуют хранимые подразделы.');

            return;
        }

        Timers::start('topics_update');
        $this->logger->info('Начато обновление сведений о раздачах в хранимых подразделах...');

        // Ограничения доступа для кандидатов в хранители.
        $user = $this->apiReport->getKeeperPermissions();

        // Выбрана опция накапливать данные о средних сидах.
        $doCollectAverageSeeds = $this->averageSeeds->enableHistory;

        // Обновим каждый хранимый подраздел.
        foreach ($subForums as $subForumId) {
            if ($user->isCandidate && !$user->checkSubsectionAccess(forumId: $subForumId)) {
                $this->skipSubsections[] = $subForumId;

                continue;
            }

            // Получаем дату предыдущего обновления подраздела.
            $forumLastUpdated = $this->updateTime->getMarkerTime(marker: $subForumId);

            // Если не прошёл час с прошлого обновления - пропускаем подраздел.
            if (time() - $forumLastUpdated->getTimestamp() < 3600) {
                $this->skipSubsections[] = $subForumId;

                continue;
            }

            // Получаем данные о раздачах.
            Timers::start("update_forum_$subForumId");
            $topicResponse = $this->apiReport->getForumTopicsData(
                forumId         : $subForumId,
                loadAverageSeeds: $doCollectAverageSeeds
            );
            if ($topicResponse instanceof ApiError) {
                $this->skipSubsections[] = $subForumId;

                $this->logger->error(
                    'Не получены данные о подразделе №{forum}',
                    ['forum' => $subForumId, 'code' => $topicResponse->code, 'text' => $topicResponse->text]
                );

                continue;
            }

            // Если дата прошлого обновления больше или равна дате в ответе API, то обновлять нечего.
            if ($forumLastUpdated >= $topicResponse->updateTime) {
                $this->skipSubsections[] = $subForumId;

                continue;
            }

            // Проверим наличие раздач в подразделе.
            if ($topicResponse->totalCount === 0) {
                $this->skipSubsections[] = $subForumId;

                $this->logger->warning(
                    'Отсутствуют раздачи в подразделе №{forum}. Вероятно он не существует.',
                    ['forum' => $subForumId]
                );

                continue;
            }

            // Запоминаем время обновления подраздела.
            $this->updateTime->addMarkerUpdate(marker: $subForumId, updateTime: $topicResponse->updateTime);

            $isDateChanged = Helper::isUtcDayChanged(
                prevDate: $forumLastUpdated,
                newDate : $topicResponse->updateTime
            );

            // Обрабатываем полученные раздачи, и записываем во временную таблицу.
            foreach ($topicResponse->topicsChunks as $topics) {
                $this->processSubsectionTopics(topics: $topics, isDateChanged: $isDateChanged);
            }

            $this->logger->debug(
                sprintf('Список раздач подраздела № %-4d ({count} шт. {size}) обновлён за {sec}.', $subForumId),
                [
                    'count' => $topicResponse->totalCount,
                    'size'  => Helper::convertBytes($topicResponse->totalSize, 9),
                    'sec'   => Timers::getExecTime("update_forum_$subForumId"),
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
        $this->moveUpdatedTopics(subForums: $subForums);

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
                    $this->markTopicDelete(topicId: $topic->id);
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
     * @param int[] $subForums
     */
    private function moveUpdatedTopics(array $subForums): void
    {
        $updatedSubsections = array_diff($subForums, $this->skipSubsections);
        if (count($updatedSubsections)) {
            // Удаляем перерегистрированные раздачи, чтобы очистить значения сидов для старой раздачи.
            $this->deleteTopics();

            // Записываем раздачи в БД.
            $this->writeTopicsToOrigin(updatedSubForums: $updatedSubsections);

            // Записываем время обновления подразделов.
            $this->updateTime->addMarkerUpdate(marker: UpdateMark::SUBSECTIONS);
            $this->updateTime->moveToOrigin();
        }
    }

    /**
     * @param int[] $updatedSubForums
     */
    private function writeTopicsToOrigin(array $updatedSubForums): void
    {
        Timers::start('writeTopicsToOrigin');
        // Переносим данные в основную таблицу.
        $countTopicsUpdate = $this->tableUpdate->writeTable();
        $countTopicsInsert = $this->tableInsert->writeTable();

        $seedersTopicsCount = $this->seedersInsert->writeTable();

        // Удаляем ненужные раздачи.
        $this->clearUnusedTopics(updatedSubForums: $updatedSubForums);

        $this->logger->debug(
            'Перенос данных из временной таблицы выполнен за {sec}',
            ['sec' => Timers::getExecTime('writeTopicsToOrigin')]
        );

        $this->logger->info('Обработано хранимых подразделов: {count} шт, уникальных раздач в них {topics} шт.', [
            'count'  => count($updatedSubForums),
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
     * @param int[] $updatedSubForums
     */
    private function clearUnusedTopics(array $updatedSubForums): void
    {
        Timers::start('clearUnusedTopics');
        $in = implode(',', $updatedSubForums);

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
        $this->db->executeStatement(sql: $query);

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
            $this->topics->deleteTopicsByIds(topics: $topics);
        }
    }
}
