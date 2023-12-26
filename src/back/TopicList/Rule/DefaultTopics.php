<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\TopicList\Filter\AverageSeed;
use KeepersTeam\Webtlo\TopicList\Filter\Keepers;
use KeepersTeam\Webtlo\TopicList\Filter\Sort;
use KeepersTeam\Webtlo\TopicList\DbHelper;
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

    public function __construct(
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
        Validate::checkDateRelease($filter);

        // Проверка выбранного статуса раздач в клиенте.
        Validate::checkClientStatus($filter);

        // Проверка значения сидов или количества хранителей.
        Validate::filterRuleIntervals($filter);

        // Фильтр по статусу раздачи на форуме.
        $status = KeysObject::create(Validate::checkTrackerStatus($filter));

        // Фильтр по приоритету хранения раздачи на форуме.
        $priority = KeysObject::create(Validate::checkKeepingPriority($filter, $this->forumId));

        // Данные для фильтрации по средним сидам.
        $seedFilter = Validate::prepareAverageSeedFilter($filter, $this->cfg);

        // Фильтры связанные со статусом хранения и количеством хранителей.
        $filterKeepers = Validate::prepareKeepersFilter($filter);

        // Фильтрация по произвольной строке.
        $filterStrings = Validate::prepareFilterStrings($filter);

        // Исключить себя из списка хранителей.
        $excludeSelfKeep = (bool)$this->cfg['exclude_self_keep'];

        // Текущий пользователь.
        $userId = (int)$this->cfg['user_id'];

        // Хранимые подразделы.
        $forumsIDs = $this->getForumIdList();
        $forum     = KeysObject::create($forumsIDs);

        // Хранители всех раздач из хранимых подразделов.
        $keepers = $this->getKeepersByForumList($forumsIDs, $userId);

        // Собрать общий запрос к базе.
        $statement = $this->getStatement(
            $this->getKeepersStatusStatement($userId, $excludeSelfKeep),
            $filter,
            $filterKeepers,
            $seedFilter,
            $forum,
            $status,
            $priority
        );

        // Получаем раздачи из базы.
        $topics = $this->selectSortedTopics(
            $sort,
            $statement,
            [...$forum->values, ...$status->values, ...$priority->values]
        );

        $topicRows  = [];
        $totalCount = $totalSize = 0;
        // Перебираем раздачи.
        foreach ($topics as $topicData) {
            $daysSeed = (int)$topicData['days_seed'];
            // Состояние раздачи в клиенте (пулька) [иконка, цвет, описание].
            $topicState = State::parseFromTorrent(
                $topicData,
                $seedFilter->seedPeriod,
                $daysSeed
            );

            // Типизируем данные раздачи в объект.
            $topic = Topic::fromTopicData($topicData, $topicState);

            unset($topicData);

            // Список хранителей раздачи.
            $topicKeepers = $keepers[$topic->id] ?? [];

            // Фильтрация по количеству сидов.
            if (!FilterApply::isSeedCountInRange($filter, $topic->averageSeed)) {
                continue;
            }

            // Фильтрация по статусу "зелёные"
            if (!FilterApply::isSeedCountGreen($seedFilter, $daysSeed)) {
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
    private function getForumIdList(): array
    {
        if ($this->forumId > 0) {
            $forumsIDs = [$this->forumId];
        } elseif ($this->forumId === -5) {
            // Высокий приоритет.
            $forumsIDs = DbHelper::getHighPriorityForums();
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

        return $forumsIDs;
    }

    private function getKeepersStatusStatement(int $userId, bool $excludeSelf): string
    {
        // Данный запрос, для каждой раздачи, определяет наличие:
        // max_posted - хранителя включившего раздачу в отчёт, по данным форума (KeepersLists);
        // has_complete - хранителя завершившего скачивание раздачи;
        // has_download - хранителя скачивающего раздачу;
        // has_seeding - хранителя раздающего раздачу, по данным апи (KeepersSeeders);
        return sprintf(
            '
                SELECT topic_id,
                    MAX(complete) AS has_complete,
                    MAX(posted) AS max_posted,
                    MAX(NOT complete) AS has_download,
                    MAX(seeding) AS has_seeding
                FROM (
                    SELECT topic_id, MAX(complete) AS complete, MAX(posted) AS posted, MAX(seeding) AS seeding
                    FROM (
                        SELECT topic_id, keeper_id, complete, CASE WHEN complete = 1 THEN posted END as posted, 0 AS seeding
                        FROM KeepersLists kl
                        INNER JOIN Topics t ON t.id = kl.topic_id
                        WHERE kl.posted > t.rg
                        UNION ALL
                        SELECT topic_id, keeper_id, 1 AS complete, NULL AS posted, 1 AS seeding
                        FROM KeepersSeeders
                    )
                    %s
                    GROUP BY topic_id, keeper_id
                )
                GROUP BY topic_id
            ',
            // Исключаем себя из списка, при необходимости.
            $excludeSelf ? "WHERE keeper_id != '$userId'" : ''
        );
    }

    private function getStatement(
        string      $keepersStatement,
        array       $filter,
        Keepers     $filterKeepers,
        AverageSeed $averageSeed,
        KeysObject  $forum,
        KeysObject  $status,
        KeysObject  $priority
    ): string {
        // Шаблон для статуса хранения.
        $torrentDone = 'CAST(done as INT) IS ' . implode(' OR CAST(done AS INT) IS ', $filter['filter_client_status']);

        // 1 - fields, 2 - left join, 3 - keepers check, 4 - where
        $statement = "
            SELECT
                Topics.id AS topic_id,
                Topics.hs AS info_hash,
                Topics.na AS name,
                Topics.si AS size,
                Topics.rg AS reg_time,
                Topics.ss AS forum_id,
                Topics.pt AS priority,
                Torrents.done,
                Torrents.paused,
                Torrents.error,
                Torrents.client_id,
                %s
            FROM Topics
            LEFT JOIN Torrents ON Topics.hs = Torrents.info_hash
            %s
            LEFT JOIN (
                %s
            ) Keepers ON Topics.id = Keepers.topic_id AND (Keepers.max_posted IS NULL OR Topics.rg < Keepers.max_posted)
            LEFT JOIN (SELECT info_hash FROM TopicsExcluded GROUP BY info_hash) TopicsExcluded ON Topics.hs = TopicsExcluded.info_hash
            WHERE TopicsExcluded.info_hash IS NULL
                AND ss IN ($forum->keys)
                AND st IN ($status->keys)
                AND pt IN ($priority->keys)
                AND ($torrentDone)
                %s
        ";

        // Применить фильтр по статусу хранимого.
        $where = Validate::getKeptStatusFilter($filterKeepers->status);

        // Фильтр по дате регистрации раздачи.
        $where[] = sprintf('AND Topics.rg < %d', Validate::getDateRelease($filter)->format('U'));

        // Фильтр по клиенту.
        if ($filter['filter_client_id'] > 0) {
            $where[] = sprintf('AND Torrents.client_id = %d', (int)$filter['filter_client_id']);
        }

        return sprintf(
            $statement,
            implode(', ', $averageSeed->fields),
            implode(' ', $averageSeed->joins),
            $keepersStatement,
            implode(' ', $where)
        );
    }

    /** Количество раздач в "чёрном списке". */
    private function getExcluded(KeysObject $forum, KeysObject $status, KeysObject $priority): Excluded
    {
        $statement = "
            SELECT COUNT(1) AS count, IFNULL(SUM(t.si),0) AS size
            FROM TopicsExcluded ex
            INNER JOIN Topics t on t.hs = ex.info_hash
            WHERE t.ss IN ($forum->keys)
                AND t.st IN ($status->keys)
                AND t.pt IN ($priority->keys)
        ";

        $excluded = DbHelper::queryStatementRow(
            $statement,
            [...$forum->values, ...$status->values, ...$priority->values]
        );

        return new Excluded($excluded['count'] ?? 0, $excluded['size'] ?? 0);
    }

    /** Список хранителей всех раздач указанных подразделов. */
    private function getKeepersByForumList(array $forumList, int $user_id): array
    {
        $keepers = [];
        foreach (array_chunk($forumList, 499) as $forumsChunk) {
            $keys = KeysObject::create($forumsChunk);

            $statement = "
                SELECT k.topic_id, k.keeper_id, k.keeper_name, MAX(k.complete) AS complete, MAX(k.posted) AS posted, MAX(k.seeding) AS seeding
                FROM (
                    SELECT kl.topic_id, kl.keeper_id, kl.keeper_name, kl.complete, CASE WHEN kl.complete = 1 THEN kl.posted END AS posted, 0 AS seeding
                    FROM Topics
                    LEFT JOIN KeepersLists AS kl ON Topics.id = kl.topic_id
                    WHERE ss IN ($keys->keys) AND rg < posted AND kl.topic_id IS NOT NULL
                    UNION ALL
                    SELECT ks.topic_id, ks.keeper_id, ks.keeper_name, 1 AS complete, 0 AS posted, 1 AS seeding
                    FROM Topics
                    LEFT JOIN KeepersSeeders AS ks ON Topics.id = ks.topic_id
                    WHERE ss IN ($keys->keys) AND ks.topic_id IS NOT NULL
                ) AS k
                GROUP BY k.topic_id, k.keeper_id, k.keeper_name
                ORDER BY (CASE WHEN k.keeper_id == ? THEN 1 ELSE 0 END) DESC, complete DESC, seeding, posted DESC, k.keeper_name
            ";

            $keepers += DbHelper::queryStatementGroup(
                $statement,
                [...$keys->values, ...$keys->values, $user_id]
            );
        }

        return $keepers;
    }

    /** Исключить себя из списка хранителей раздачи. */
    private function excludeUserFromKeepers(array $topicKeepers, int $userId): array
    {
        return array_filter($topicKeepers, function($e) use ($userId) {
            return $userId !== (int)$e['keeper_id'];
        });
    }
}