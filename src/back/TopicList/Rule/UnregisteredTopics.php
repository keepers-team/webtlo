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
            SELECT
                Torrents.topic_id AS topic_id,
                COALESCE(TopicsUnregistered.name, Torrents.name) AS name,
                COALESCE(Torrents.name, '') AS prev,
                TopicsUnregistered.status,
                Torrents.info_hash,
                Torrents.total_size AS size,
                Torrents.time_added AS reg_time,
                -1 AS seed,
                -1 AS days_seed,
                Torrents.client_id AS client_id,
                Torrents.paused,
                Torrents.error,
                Torrents.tracker_error AS error_message,
                Torrents.done
            FROM TopicsUnregistered
            INNER JOIN Torrents ON TopicsUnregistered.info_hash = Torrents.info_hash
            ORDER BY {$sort->fieldDirection()}
        ";

        $topics = $this->selectTopics(statement: $statement);

        $groups = [];
        foreach ($topics as $topicData) {
            $topicStatus = $topicData['status'];
            // Состояние раздачи в клиенте (пулька) [иконка, цвет, описание].
            $topicState = State::clientOnly(topicData: $topicData);

            $details = [];
            // Если имя раздачи отличается от имени в клиенте - выводим оба имени.
            if (!empty($topicData['prev']) && $topicData['prev'] !== $topicData['name']) {
                $details['previous_name'] = $topicData['prev'];
            }

            // Типизируем данные раздачи в объект.
            $topic = Topic::fromTopicData(topicData: $topicData, state: $topicState);
            unset($topicData);

            if (!isset($groups[$topicStatus])) {
                $groups[$topicStatus] = new TopicGroup(
                    key: $topicStatus,
                    title: $topicStatus,
                );
            }

            // Выводим строку с данными раздачи.
            $groups[$topicStatus]->topics[] = new TopicResult(topic: $topic, details: $details);
        }
        unset($topics);

        $groups = self::sortGroups(groups: $groups);

        return new Topics(groups: array_values($groups));
    }
}
