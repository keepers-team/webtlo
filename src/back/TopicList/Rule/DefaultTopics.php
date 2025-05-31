<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use DateTimeImmutable;
use Generator;
use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Storage\KeysObject;
use KeepersTeam\Webtlo\TopicList\ConfigFilter;
use KeepersTeam\Webtlo\TopicList\Excluded;
use KeepersTeam\Webtlo\TopicList\Filter\AverageSeed;
use KeepersTeam\Webtlo\TopicList\Filter\Keepers;
use KeepersTeam\Webtlo\TopicList\Filter\Sort;
use KeepersTeam\Webtlo\TopicList\FilterApply;
use KeepersTeam\Webtlo\TopicList\Formatter;
use KeepersTeam\Webtlo\TopicList\State;
use KeepersTeam\Webtlo\TopicList\Topic;
use KeepersTeam\Webtlo\TopicList\Topics;
use KeepersTeam\Webtlo\TopicList\Validate;
use KeepersTeam\Webtlo\TopicList\ValidationException;

final class DefaultTopics implements ListInterface
{
    use DbHelperTrait;
    use FilterTrait;
    use FormatKeepersTrait;

    /** @var array<string, mixed>[][] */
    private array $keepers = [];

    public function __construct(
        private readonly DB           $db,
        private readonly ConfigFilter $configFilter,
        private readonly Formatter    $formatter,
        private readonly int          $forumId
    ) {}

    /**
     * @throws ValidationException
     */
    public function getTopics(array $filter, Sort $sort): Topics
    {
        // Проверка фильтра даты регистрации раздачи.
        $dateRelease = Validate::checkDateRelease($filter);

        // Проверка выбранного статуса раздач в клиенте.
        Validate::checkClientStatus($filter);

        // Проверка значения сидов или количества хранителей.
        Validate::filterRuleIntervals($filter);

        // Фильтр по статусу раздачи на форуме.
        $status = KeysObject::create(Validate::checkTrackerStatus($filter));

        // Фильтр по приоритету хранения раздачи на форуме.
        $priority = KeysObject::create(Validate::checkKeepingPriority($filter, $this->forumId));

        // Данные для фильтрации по количеству сидов раздачи.
        $filterSeed = Validate::prepareSeedFilter($filter);

        // Данные для фильтрации по средним сидам.
        $filterAverageSeed = Validate::prepareAverageSeedFilter($filter, $this->configFilter->enableAverageHistory);

        // Фильтры связанные со статусом хранения и количеством хранителей.
        $filterKeepers = Validate::prepareKeepersFilter($filter);

        // Фильтрация по произвольной строке.
        $filterStrings = Validate::prepareFilterStrings($filter);

        // Исключить себя из списка хранителей.
        $excludeSelfKeep = $this->configFilter->excludeSelf;

        // Текущий пользователь.
        $userId = $this->configFilter->userId;

        // Хранимые подразделы.
        $forum = $this->getForumIdList();

        // Ищем раздачи по фильтрам.
        $topics = $this->queryTopics(
            $filter,
            $filterKeepers,
            $filterAverageSeed,
            $forum,
            $status,
            $priority,
            $dateRelease,
            $excludeSelfKeep,
            $sort,
        );

        $topicRows  = [];
        $totalCount = $totalSize = 0;
        // Перебираем раздачи.
        foreach ($topics as $topicData) {
            $daysSeed = (int) $topicData['days_seed'];
            // Состояние раздачи в клиенте (пулька) [иконка, цвет, описание].
            $topicState = State::parseFromTorrent(
                $topicData,
                $filterAverageSeed->seedPeriod,
                $daysSeed
            );

            // Типизируем данные раздачи в объект.
            $topic = Topic::fromTopicData($topicData, $topicState);

            unset($topicData);

            // Список хранителей раздачи.
            $topicKeepers = $this->getTopicKeepers($topic->id);

            // Фильтрация по количеству сидов.
            if (!FilterApply::isSeedCountInRange($filterSeed, (float) $topic->averageSeed)) {
                continue;
            }

            // Фильтрация по статусу "зелёные"
            if (!FilterApply::isSeedCountGreen($filterAverageSeed, $daysSeed)) {
                continue;
            }

            // Фильтрация раздач по своим спискам.
            if ($this->forumId == -6) {
                $excludeSelfKeep = false;

                if (!FilterApply::isUserInKeepers($topicKeepers, $userId)) {
                    continue;
                }
            }

            // Исключим себя из списка хранителей раздачи.
            if ($excludeSelfKeep) {
                $topicKeepers = self::excludeUserFromKeepers($topicKeepers, $userId);
            }

            // Фильтрация по фразе.
            if (!FilterApply::isStringsMatch($filterStrings, $topic, $topicKeepers)) {
                continue;
            }

            // Фильтрация по количеству хранителей
            if (!FilterApply::isTopicKeepersInRange($filterKeepers->count, $topicKeepers)) {
                continue;
            }

            ++$totalCount;
            $totalSize += $topic->size;

            // Выводим строку с данными раздачи.
            $topicRows[] = $this->formatter->formatTopic(
                topic  : $topic,
                details: self::getFormattedKeepersList($topicKeepers, $userId)
            );

            unset($daysSeed, $topicState, $topicKeepers, $topic);
        }

        // Раздачи подраздела в "чёрном списке".
        $excluded = $this->getExcluded($forum, $status, $priority);

        return new Topics($totalCount, $totalSize, $topicRows, $excluded);
    }

    /** Список ид подразделов. */
    private function getForumIdList(): KeysObject
    {
        if ($this->forumId > 0) {
            $forumsIDs = [$this->forumId];
        } elseif ($this->forumId === -5) {
            // Высокий приоритет.
            $forumsIDs = $this->getHighPriorityForums();
        } else {
            // -3 Все хранимые подразделы.
            // -6 Все хранимые подразделы по спискам.
            $forumsIDs = $this->configFilter->notHiddenSubForums;
        }

        if (empty($forumsIDs)) {
            $forumsIDs = [0];
        }

        return KeysObject::create($forumsIDs);
    }

    /**
     * Создать временные таблицы, сформировать запрос поиска раздач, выполнить запрос к БД, обернув в транзакцию.
     *
     * @param array<string, mixed> $filter
     */
    private function queryTopics(
        array             $filter,
        Keepers           $filterKeepers,
        AverageSeed       $filterAverageSeed,
        KeysObject        $forum,
        KeysObject        $status,
        KeysObject        $priority,
        DateTimeImmutable $dateRelease,
        bool              $excludeSelfKeep,
        Sort              $sort
    ): Generator {
        // Открываем транзакцию выполнения запроса к БД.
        $this->db->beginTransaction();

        // Создаём временные таблицы.
        $this->createTempTopics($forum, $status, $priority, $dateRelease);
        $this->createTempKeepers();
        $this->createTempKeepersFilter($excludeSelfKeep);

        // Хранители всех раздач из искомых подразделов.
        $this->fillKeepersByForumList(
            Helper::convertKeysToInt($forum->values)
        );

        // Собрать общий запрос к базе.
        $statement = $this->getStatement(
            $filter,
            $filterKeepers,
            $filterAverageSeed,
            $sort
        );

        // Получаем раздачи из базы.
        $topics = $this->selectTopics($statement);

        // Закрываем транзакцию к БД.
        $this->db->commitTransaction();

        foreach ($topics as $topic) {
            yield $topic;
        }
    }

    /**
     * Создать временную таблицу Topics по имеющимся фильтрам.
     */
    private function createTempTopics(
        KeysObject        $forum,
        KeysObject        $status,
        KeysObject        $priority,
        DateTimeImmutable $dateRelease,
    ): void {
        $sql = /** @lang SQLite */
            "
            CREATE TEMP TABLE DefaultRuleTopics AS
            SELECT *
            FROM Topics
            WHERE TRUE
                AND Topics.forum_id IN ($forum->keys)
                AND Topics.status IN ($status->keys)
                AND Topics.keeping_priority IN ($priority->keys)
                AND Topics.reg_time < ?
        ";

        $params = [
            // Фильтр выбранных подразделов.
            ...$forum->values,
            // Фильтр по статусу раздачи.
            ...$status->values,
            // Фильтр по приоритету хранения раздачи
            ...$priority->values,
            // Фильтр по дате регистрации раздачи.
            $dateRelease->getTimestamp(),
        ];

        $this->queryStatement($sql, $params);

        // Добавим индекс по ид раздачи.
        $this->queryStatement('CREATE INDEX temp.DefaultRuleTopicsIX_id ON DefaultRuleTopics(id)');
    }

    private function createTempKeepers(): void
    {
        $sql = /** @lang SQLite */
            '
            CREATE TEMP TABLE DefaultRuleKeepers AS
            SELECT kl.topic_id, kl.keeper_id, kl.keeper_name, kl.complete, CASE WHEN kl.complete = 1 THEN kl.posted END AS posted, 0 AS seeding
            FROM KeepersLists kl
            INNER JOIN temp.DefaultRuleTopics t ON t.id = kl.topic_id
            WHERE kl.posted > t.reg_time
            UNION ALL
            SELECT ks.topic_id, ks.keeper_id, ks.keeper_name, 1 AS complete, NULL AS posted, 1 AS seeding
            FROM KeepersSeeders AS ks
            INNER JOIN temp.DefaultRuleTopics t ON t.id = ks.topic_id
        ';

        $this->queryStatement($sql);
    }

    private function createTempKeepersFilter(bool $excludeSelf): void
    {
        $selfFilter = $excludeSelf ? "WHERE keeper_id != '{$this->configFilter->userId}'" : '';

        // Данный запрос, для каждой раздачи, определяет наличие:
        // max_posted - хранителя включившего раздачу в отчёт, по данным форума (KeepersLists);
        // has_complete - хранителя завершившего скачивание раздачи;
        // has_download - хранителя скачивающего раздачу;
        // has_seeding - хранителя раздающего раздачу, по данным апи (KeepersSeeders);
        $sql = /** @lang SQLite */
            "
            CREATE TEMP TABLE DefaultRuleKeepersFilter AS
            SELECT topic_id,
                MAX(complete) AS has_complete,
                MAX(posted) AS max_posted,
                MAX(NOT complete) AS has_download,
                MAX(seeding) AS has_seeding
            FROM (
                SELECT topic_id, MAX(complete) AS complete, MAX(posted) AS posted, MAX(seeding) AS seeding
                FROM temp.DefaultRuleKeepers AS tmp
                $selfFilter
                GROUP BY topic_id, keeper_id
            )
            GROUP BY topic_id
        ";

        $this->queryStatement($sql);
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function getStatement(
        array       $filter,
        Keepers     $filterKeepers,
        AverageSeed $averageSeed,
        Sort        $sort
    ): string {
        // Шаблон для статуса хранения.
        $torrentDone = 'CAST(done as INT) IS ' . implode(' OR CAST(done AS INT) IS ', $filter['filter_client_status']);

        // Применить фильтр по статусу хранимого.
        $where = Validate::getKeptStatusFilter($filterKeepers->status);

        // Фильтр по клиенту.
        if ($filter['filter_client_id'] > 0) {
            $where[] = sprintf('AND Torrents.client_id = %d', (int) $filter['filter_client_id']);
        }

        // Поиск раздач с ошибкой в клиенте.
        if (!empty($filter['filter_topic_has_client_error'])) {
            $where[] = 'AND Torrents.error = 1';
        }

        $where = implode(' ', $where);

        // Собираем запрос целиком.
        return "
            SELECT
                Topics.id AS topic_id,
                Topics.info_hash,
                Topics.name AS name,
                Topics.size AS size,
                Topics.reg_time AS reg_time,
                Topics.forum_id,
                Topics.keeping_priority AS priority,
                Torrents.done,
                Torrents.paused,
                Torrents.error,
                Torrents.tracker_error AS error_message,
                Torrents.client_id AS client_id,
                {$averageSeed->getFields()}
            FROM temp.DefaultRuleTopics AS Topics
            LEFT JOIN Torrents ON Topics.info_hash = Torrents.info_hash
            {$averageSeed->getJoins()}
            LEFT JOIN temp.DefaultRuleKeepersFilter AS Keepers
                ON Topics.id = Keepers.topic_id
                    AND (Keepers.max_posted IS NULL OR Topics.reg_time < Keepers.max_posted)
            LEFT JOIN (SELECT info_hash FROM TopicsExcluded GROUP BY info_hash) TopicsExcluded
                ON Topics.info_hash = TopicsExcluded.info_hash
            WHERE TopicsExcluded.info_hash IS NULL
                AND ($torrentDone)
                {$where}
            ORDER BY {$sort->fieldDirection()}
        ";
    }

    /** Количество раздач в "чёрном списке". */
    private function getExcluded(KeysObject $forum, KeysObject $status, KeysObject $priority): Excluded
    {
        $statement = "
            SELECT COUNT(1) AS count, IFNULL(SUM(t.size),0) AS size
            FROM TopicsExcluded ex
            INNER JOIN Topics t on t.info_hash = ex.info_hash
            WHERE t.forum_id IN ($forum->keys)
                AND t.status IN ($status->keys)
                AND t.keeping_priority IN ($priority->keys)
        ";

        $excluded = $this->queryStatementRow(
            $statement,
            [...$forum->values, ...$status->values, ...$priority->values]
        );

        return new Excluded($excluded['count'] ?? 0, $excluded['size'] ?? 0);
    }

    /**
     * Список хранителей всех раздач указанных подразделов.
     *
     * @param int[] $forumList
     */
    private function fillKeepersByForumList(array $forumList): void
    {
        $keepers = [];
        foreach (array_chunk($forumList, 499) as $forumsChunk) {
            $chunk = KeysObject::create($forumsChunk);

            $statement = "
                SELECT k.topic_id, k.keeper_id, k.keeper_name, MAX(k.complete) AS complete, MAX(k.posted) AS posted, MAX(k.seeding) AS seeding
                FROM (
                    SELECT kp.topic_id, kp.keeper_id, kp.keeper_name, kp.complete, CASE WHEN kp.complete = 1 THEN kp.posted END AS posted, kp.seeding
                    FROM temp.DefaultRuleTopics AS tp
                    INNER JOIN temp.DefaultRuleKeepers AS kp ON tp.id = kp.topic_id
                    WHERE tp.forum_id IN ($chunk->keys) AND (kp.posted IS NULL OR kp.posted > tp.reg_time)
                ) AS k
                GROUP BY k.topic_id, k.keeper_id, k.keeper_name
                ORDER BY (CASE WHEN k.keeper_id == ? THEN 1 ELSE 0 END) DESC, complete DESC, seeding, posted DESC, k.keeper_name
            ";

            $keepers += $this->queryStatementGroup(
                $statement,
                [...$chunk->values, $this->configFilter->userId]
            );
        }

        $this->keepers = $keepers;
    }

    /**
     * @return array<string, mixed>[]
     */
    private function getTopicKeepers(int $topicId): array
    {
        return $this->keepers[$topicId] ?? [];
    }

    /**
     * Исключить себя из списка хранителей раздачи.
     *
     * @param array<string, mixed>[] $topicKeepers
     *
     * @return array<string, mixed>[]
     */
    private static function excludeUserFromKeepers(array $topicKeepers, int $userId): array
    {
        return array_filter($topicKeepers, static function($e) use ($userId) {
            return $userId !== (int) $e['keeper_id'];
        });
    }
}
