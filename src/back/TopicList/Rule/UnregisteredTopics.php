<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use KeepersTeam\Webtlo\Infrastructure\Database\ConnectionInterface;
use KeepersTeam\Webtlo\TopicList\Filter\Sort;
use KeepersTeam\Webtlo\TopicList\State;
use KeepersTeam\Webtlo\TopicList\Topic;
use KeepersTeam\Webtlo\TopicList\TopicGroup;
use KeepersTeam\Webtlo\TopicList\TopicResult;
use KeepersTeam\Webtlo\TopicList\Topics;

/** Хранимые раздачи незарегистрированные на трекере. */
final class UnregisteredTopics implements ListInterface
{
    use FilterTrait;
    use NatSortTrait;

    public function __construct(
        private readonly ConnectionInterface $con,
    ) {}

    public function getTopics(array $filter, Sort $sort): Topics
    {
        $statement = "
            WITH updated_topics AS (
                SELECT DISTINCT tr.topic_id
                FROM TopicsUnregistered AS unregistered
                INNER JOIN Torrents AS tr ON tr.info_hash = unregistered.info_hash
                WHERE unregistered.status LIKE 'обновлено (%'
            ), current_versions AS (
                SELECT DISTINCT tr.topic_id, tr.client_id
                FROM Torrents AS tr
                LEFT JOIN TopicsUnregistered AS unregistered ON unregistered.info_hash = tr.info_hash
                WHERE tr.topic_id IN (SELECT topic_id FROM updated_topics)
                  AND unregistered.info_hash IS NULL
            )
            SELECT
                Torrents.topic_id AS topic_id,
                COALESCE(TopicsUnregistered.name, Torrents.name) AS name,
                COALESCE(Torrents.name, '') AS prev,
                TopicsUnregistered.status,
                Torrents.info_hash,
                Torrents.total_size AS size,
                COALESCE(tp.reg_time, Torrents.time_added) AS reg_time,
                -1 AS seed,
                -1 AS days_seed,
                Torrents.client_id AS client_id,
                Torrents.paused,
                Torrents.error,
                Torrents.tracker_error AS error_message,
                Torrents.done,
                tp.info_hash AS updated_hash,
                current_versions.topic_id IS NOT NULL AS current_added
            FROM TopicsUnregistered
            INNER JOIN Torrents ON TopicsUnregistered.info_hash = Torrents.info_hash
            LEFT JOIN Topics AS tp ON tp.id = Torrents.topic_id
            LEFT JOIN current_versions
                ON current_versions.topic_id = Torrents.topic_id
               AND current_versions.client_id = Torrents.client_id
            ORDER BY {$sort->fieldDirection()}
        ";

        $topics = $this->selectTopics(statement: $statement);

        $groups = [];
        foreach ($topics as $topicData) {
            $topicStatus  = (string) $topicData['status'];
            $currentAdded = (bool) $topicData['current_added']
                && str_starts_with($topicStatus, 'обновлено (');
            $groupTitle   = $currentAdded ? 'Старая версия уже обновлена' : $topicStatus;

            // Состояние раздачи в клиенте (пулька) [иконка, цвет, описание].
            $topicState = State::clientOnly(topicData: $topicData);

            $details = [];
            // Если имя раздачи отличается от имени в клиенте - выводим оба имени.
            if (!empty($topicData['prev']) && $topicData['prev'] !== $topicData['name']) {
                $details['previous_name'] = $topicData['prev'];
            }

            if (!empty($topicData['updated_hash'])) {
                $details['updated_hash'] = $topicData['updated_hash'];
            }
            if ($currentAdded) {
                $details['current_added'] = true;
                $details['original_status'] = $topicStatus;
            }

            // Типизируем данные раздачи в объект.
            $topic = Topic::fromTopicData(topicData: $topicData, state: $topicState);
            unset($topicData);

            if (!isset($groups[$groupTitle])) {
                $groups[$groupTitle] = new TopicGroup(
                    key: $groupTitle,
                    title: $groupTitle,
                );
            }

            // Выводим строку с данными раздачи.
            $groups[$groupTitle]->topics[] = new TopicResult(topic: $topic, details: $details);
        }
        unset($topics);

        $groups = self::sortGroups(groups: $groups);

        return new Topics(groups: array_values($groups));
    }
}
