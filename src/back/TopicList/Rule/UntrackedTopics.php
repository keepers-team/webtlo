<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use KeepersTeam\Webtlo\Infrastructure\Database\ConnectionInterface;
use KeepersTeam\Webtlo\Storage\Table\Forums;
use KeepersTeam\Webtlo\TopicList\Filter\Sort;
use KeepersTeam\Webtlo\TopicList\State;
use KeepersTeam\Webtlo\TopicList\Topic;
use KeepersTeam\Webtlo\TopicList\TopicGroup;
use KeepersTeam\Webtlo\TopicList\TopicResult;
use KeepersTeam\Webtlo\TopicList\Topics;

/** Хранимые раздачи из других подразделов. */
final class UntrackedTopics implements ListInterface
{
    use FilterTrait;
    use NatSortTrait;

    public function __construct(
        private readonly ConnectionInterface $con,
        private readonly Forums              $forums,
    ) {}

    public function getTopics(array $filter, Sort $sort): Topics
    {
        $statement = "
            SELECT
                TopicsUntracked.id AS topic_id,
                TopicsUntracked.info_hash,
                TopicsUntracked.name AS name,
                TopicsUntracked.size AS size,
                TopicsUntracked.reg_time AS reg_time,
                TopicsUntracked.forum_id,
                TopicsUntracked.seeders AS seed,
                -1 AS days_seed,
                Torrents.done,
                Torrents.paused,
                Torrents.error,
                Torrents.tracker_error AS error_message,
                Torrents.client_id AS client_id
            FROM TopicsUntracked
            LEFT JOIN Torrents ON Torrents.info_hash = TopicsUntracked.info_hash
            WHERE TopicsUntracked.info_hash IS NOT NULL
            ORDER BY {$sort->fieldDirection()}
        ";

        $topics = $this->selectTopics(statement: $statement);

        $groups = [];
        // Типизируем данные раздач в объекты.
        foreach ($topics as $topicData) {
            // Состояние раздачи в клиенте (пулька) [иконка, цвет, описание].
            $topicState = State::clientOnly(topicData: $topicData);

            $topic = Topic::fromTopicData(topicData: $topicData, state: $topicState);
            unset($topicData);

            if ($topic->forumId === null) {
                continue;
            }

            if (!isset($groups[$topic->forumId])) {
                $groups[$topic->forumId] = new TopicGroup(
                    key     : $topic->forumId,
                    title   : $this->forums->getForumName(forumId: $topic->forumId),
                    metadata: ['add_unsaved_subsection' => true],
                );
            }

            $groups[$topic->forumId]->topics[] = new TopicResult(topic: $topic);
        }
        unset($topics);

        $groups = self::sortGroups(groups: $groups);

        return new Topics(groups: array_values($groups));
    }
}
