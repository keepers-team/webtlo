<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use KeepersTeam\Webtlo\Module\Forums;
use KeepersTeam\Webtlo\TopicList\Filter\Sort;
use KeepersTeam\Webtlo\TopicList\Topic;
use KeepersTeam\Webtlo\TopicList\Topics;
use KeepersTeam\Webtlo\TopicList\Output;

/** Раздачи из "Черного списка". */
final class BlackListedTopics implements ListInterface
{
    use FilterTrait;

    public function __construct(
        private readonly array  $cfg,
        private readonly Output $output
    ) {
    }

    /** Хранимые раздачи из других подразделов. */
    public function getTopics(array $filter, Sort $sort): Topics
    {
        $statement = sprintf(
            "
                SELECT
                    Topics.id AS topic_id,
                    Topics.hs AS info_hash,
                    Topics.na AS name,
                    Topics.si AS size,
                    Topics.rg AS reg_time,
                    Topics.ss AS forum_id,
                    Topics.pt AS priority,
                    0 AS client_id,
                    %s,
                    TopicsExcluded.comment
                FROM Topics
                LEFT JOIN TopicsExcluded ON Topics.hs = TopicsExcluded.info_hash
                WHERE TopicsExcluded.info_hash IS NOT NULL
            ",
            $this->cfg['avg_seeders'] ? '(se * 1.) / qt AS seed' : 'se AS seed'
        );

        $topics = $this->selectSortedTopics($sort, $statement);

        // Типизируем данные раздач в объекты.
        $topics = array_map(fn($row) => Topic::fromTopicData($row), $topics);

        $counter = new Topics();
        foreach ($topics as $topic) {
            $counter->count++;
            $counter->size += $topic->size;

            if (!isset($counter->list[$topic->forumId])) {
                $counter->list[$topic->forumId] = sprintf(
                    "<div class='subsection-title'>%s [%d]</div>",
                    Forums::getForumName($topic->forumId),
                    $topic->forumId,
                );
            }

            // Выводим строку с данными раздачи.
            $counter->list[$topic->forumId] .= $this->output->formatTopic($topic);
        }
        unset($topics);

        natcasesort($counter->list);

        return $counter;
    }
}