<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use KeepersTeam\Webtlo\Infrastructure\Database\ConnectionInterface;
use KeepersTeam\Webtlo\Storage\Table\Forums;
use KeepersTeam\Webtlo\TopicList\Filter\Sort;
use KeepersTeam\Webtlo\TopicList\Topic;
use KeepersTeam\Webtlo\TopicList\TopicGroup;
use KeepersTeam\Webtlo\TopicList\TopicResult;
use KeepersTeam\Webtlo\TopicList\Topics;

/** Раздачи из "Черного списка". */
final class BlackListedTopics implements ListInterface
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
                tp.id AS topic_id,
                tp.info_hash,
                tp.name AS name,
                tp.size AS size,
                tp.reg_time AS reg_time,
                tp.forum_id,
                tp.keeping_priority AS priority,
                0 AS client_id,
                tp.seeders / tp.seeders_updates_today AS seed,
                te.comment
            FROM Topics AS tp
            LEFT JOIN TopicsExcluded AS te ON tp.info_hash = te.info_hash
            WHERE te.info_hash IS NOT NULL
            ORDER BY {$sort->fieldDirection()}
        ";

        $topics = $this->selectTopics(statement: $statement);

        $groups = [];
        foreach ($topics as $topicData) {
            $topic = Topic::fromTopicData(topicData: $topicData);
            unset($topicData);

            if ($topic->forumId === null) {
                continue;
            }

            if (!isset($groups[$topic->forumId])) {
                $groups[$topic->forumId] = new TopicGroup(
                    key: $topic->forumId,
                    title: $this->forums->getForumName(forumId: $topic->forumId)
                );
            }

            // Выводим строку с данными раздачи.
            $groups[$topic->forumId]->topics[] = new TopicResult(topic: $topic);
        }
        unset($topics);

        $groups = self::sortGroups(groups: $groups);

        return new Topics(groups: array_values($groups));
    }
}
