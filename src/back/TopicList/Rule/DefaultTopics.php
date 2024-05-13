<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use DateTimeImmutable;
use Generator;
use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\TopicList\Filter\AverageSeed;
use KeepersTeam\Webtlo\TopicList\Filter\Keepers;
use KeepersTeam\Webtlo\TopicList\Filter\Sort;
use KeepersTeam\Webtlo\TopicList\Helper;
use KeepersTeam\Webtlo\TopicList\Validate;
use KeepersTeam\Webtlo\TopicList\FilterApply;
use KeepersTeam\Webtlo\TopicList\State;
use KeepersTeam\Webtlo\TopicList\Topic;
use KeepersTeam\Webtlo\TopicList\Topics;
use KeepersTeam\Webtlo\TopicList\Output;
use KeepersTeam\Webtlo\TopicList\Excluded;
use Exception;

final class DefaultTopics implements ListInterface
{
    use FilterTrait;
    use DbHelperTrait;

    private int   $userId;
    private array $keepers = [];

    public function __construct(
        private readonly DB     $db,
        private readonly array  $cfg,
        private readonly Output $output,
        private readonly int    $forumId
    ) {
    }

    /**
     * @throws Exception
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
        $filterAverageSeed = Validate::prepareAverageSeedFilter($filter, $this->cfg);

        // Фильтры связанные со статусом хранения и количеством хранителей.
        $filterKeepers = Validate::prepareKeepersFilter($filter);

        // Фильтрация по произвольной строке.
        $filterStrings = Validate::prepareFilterStrings($filter);

        // Исключить себя из списка хранителей.
        $excludeSelfKeep = (bool)$this->cfg['exclude_self_keep'];

        // Текущий пользователь.
        $userId = (int)$this->cfg['user_id'];

        $this->userId = $userId;

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
            $daysSeed = (int)$topicData['days_seed'];
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
            if (!FilterApply::isSeedCountInRange($filterSeed, (float)$topic->averageSeed)) {
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
                $topicKeepers = $this->excludeUserFromKeepers($topicKeepers, $userId);
            }

            // Фильтрация по фразе.
            if (!FilterApply::isStringsMatch($filterStrings, $topic, $topicKeepers)) {
                continue;
            }

            // Фильтрация по количеству хранителей
            if (!FilterApply::isTopicKeepersInRange($filterKeepers->count, $topicKeepers)) {
                continue;
            }

            $totalCount++;
            $totalSize += $topic->size;

            // Выводим строку с данными раздачи.
            $topicRows[] = $this->output->formatTopic(
                $topic,
                Helper::getFormattedKeepersList($topicKeepers, $userId)
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
            $forumsIDs = [];
            // -3 Все хранимые подразделы.
            // -6 Все хранимые подразделы по спискам.
            $subsections = (array)($this->cfg['subsections'] ?? []);
            foreach ($subsections as $sub_forum_id => $subsection) {
                if (!$subsection['hide_topics']) {
                    $forumsIDs[] = $sub_forum_id;
                }
            }
        }
        if (empty($forumsIDs)) {
            $forumsIDs = [0];
        }

        return KeysObject::create($forumsIDs);
    }

    /**
     * Создать временные таблицы, сформировать запрос поиска раздач, выполнить запрос к БД, обернув в транзакцию.
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
        $this->createTempKeepers($excludeSelfKeep);

        // Хранители всех раздач из искомых подразделов.
        $this->fillKeepersByForumList($forum->values);

        // Собрать общий запрос к базе.
        $statement = $this->getStatement(
            $filter,
            $filterKeepers,
            $filterAverageSeed,
        );

        // Получаем раздачи из базы.
        $topics = $this->selectSortedTopics(
            $sort,
            $statement,
        );

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

    private function createTempKeepers(bool $excludeSelf): void
    {
        $selfFilter = $excludeSelf ? "WHERE keeper_id != '$this->userId'" : '';

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
                FROM (
                    SELECT kl.topic_id, kl.keeper_id, kl.complete, CASE WHEN kl.complete = 1 THEN kl.posted END AS posted, 0 AS seeding
                    FROM KeepersLists kl
                    INNER JOIN DefaultRuleTopics t ON t.id = kl.topic_id
                    WHERE kl.posted > t.reg_time
                    UNION ALL
                    SELECT ks.topic_id, ks.keeper_id, 1 AS complete, NULL AS posted, 1 AS seeding
                    FROM KeepersSeeders AS ks
                    INNER JOIN DefaultRuleTopics t ON t.id = ks.topic_id
                )
                $selfFilter
                GROUP BY topic_id, keeper_id
            )
            GROUP BY topic_id
        ";

        $this->queryStatement($sql);
    }

    private function getStatement(
        array       $filter,
        Keepers     $filterKeepers,
        AverageSeed $averageSeed,
    ): string {
        // Шаблон для статуса хранения.
        $torrentDone = 'CAST(done as INT) IS ' . implode(' OR CAST(done AS INT) IS ', $filter['filter_client_status']);

        // 1 - fields, 2 - left join, 3 - keepers check, 4 - where
        $statement = "
            SELECT
                Topics.id AS topic_id,
                Topics.info_hash,
                Topics.name,
                Topics.size,
                Topics.reg_time,
                Topics.forum_id,
                Topics.keeping_priority AS priority,
                Torrents.done,
                Torrents.paused,
                Torrents.error,
                Torrents.client_id,
                %s
            FROM temp.DefaultRuleTopics AS Topics
            LEFT JOIN Torrents ON Topics.info_hash = Torrents.info_hash
            %s
            LEFT JOIN temp.DefaultRuleKeepersFilter AS Keepers
                ON Topics.id = Keepers.topic_id
                    AND (Keepers.max_posted IS NULL OR Topics.reg_time < Keepers.max_posted)
            LEFT JOIN (SELECT info_hash FROM TopicsExcluded GROUP BY info_hash) TopicsExcluded
                ON Topics.info_hash = TopicsExcluded.info_hash
            WHERE TopicsExcluded.info_hash IS NULL
                AND ($torrentDone)
                %s
        ";

        // Применить фильтр по статусу хранимого.
        $where = Validate::getKeptStatusFilter($filterKeepers->status);

        // Фильтр по клиенту.
        if ($filter['filter_client_id'] > 0) {
            $where[] = sprintf('AND Torrents.client_id = %d', (int)$filter['filter_client_id']);
        }

        return sprintf(
            $statement,
            implode(', ', $averageSeed->fields),
            implode(' ', $averageSeed->joins),
            implode(' ', $where)
        );
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

    /** Список хранителей всех раздач указанных подразделов. */
    private function fillKeepersByForumList(array $forumList): void
    {
        $keepers = [];
        foreach (array_chunk($forumList, 499) as $forumsChunk) {
            $keys = KeysObject::create($forumsChunk);

            $statement = "
                SELECT k.topic_id, k.keeper_id, k.keeper_name, MAX(k.complete) AS complete, MAX(k.posted) AS posted, MAX(k.seeding) AS seeding
                FROM (
                    SELECT kl.topic_id, kl.keeper_id, kl.keeper_name, kl.complete, CASE WHEN kl.complete = 1 THEN kl.posted END AS posted, 0 AS seeding
                    FROM DefaultRuleTopics AS tp
                    LEFT JOIN KeepersLists AS kl ON tp.id = kl.topic_id
                    WHERE tp.forum_id IN ($keys->keys) AND tp.reg_time < posted AND kl.topic_id IS NOT NULL
                    UNION ALL
                    SELECT ks.topic_id, ks.keeper_id, ks.keeper_name, 1 AS complete, 0 AS posted, 1 AS seeding
                    FROM DefaultRuleTopics AS tp
                    LEFT JOIN KeepersSeeders AS ks ON tp.id = ks.topic_id
                    WHERE tp.forum_id IN ($keys->keys) AND ks.topic_id IS NOT NULL
                ) AS k
                GROUP BY k.topic_id, k.keeper_id, k.keeper_name
                ORDER BY (CASE WHEN k.keeper_id == ? THEN 1 ELSE 0 END) DESC, complete DESC, seeding, posted DESC, k.keeper_name
            ";

            $keepers += $this->queryStatementGroup(
                $statement,
                [...$keys->values, ...$keys->values, $this->userId]
            );
        }

        $this->keepers = $keepers;
    }

    private function getTopicKeepers(int $topicId): array
    {
        return $this->keepers[$topicId] ?? [];
    }

    /** Исключить себя из списка хранителей раздачи. */
    private function excludeUserFromKeepers(array $topicKeepers, int $userId): array
    {
        return array_filter($topicKeepers, function($e) use ($userId) {
            return $userId !== (int)$e['keeper_id'];
        });
    }
}
