<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\Storage\Table\Forums;
use KeepersTeam\Webtlo\TopicList\Filter\Sort;
use KeepersTeam\Webtlo\TopicList\Formatter;
use KeepersTeam\Webtlo\TopicList\Topic;
use KeepersTeam\Webtlo\TopicList\Topics;

/** Раздачи из "Черного списка". */
final class BlackListedTopics implements ListInterface
{
    use FilterTrait;

    public function __construct(
        private readonly DB        $db,
        private readonly Forums    $forums,
        private readonly Formatter $output,
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

        // Типизируем данные раздач в объекты.
        $topics = array_map(static fn($row) => Topic::fromTopicData(topicData: $row), $topics);

        $counter = new Topics();
        foreach ($topics as $topic) {
            ++$counter->count;
            $counter->size += $topic->size;

            if (!isset($counter->list[$topic->forumId])) {
                $counter->list[$topic->forumId] = sprintf(
                    "<div class='subsection-title'>%s [%d]</div>",
                    $this->forums->getForumName(forumId: $topic->forumId),
                    $topic->forumId,
                );
            }

            // Выводим строку с данными раздачи.
            $counter->list[$topic->forumId] .= $this->output->formatTopic(topic: $topic);
        }
        unset($topics);

        natcasesort($counter->list);

        return $counter;
    }
}
