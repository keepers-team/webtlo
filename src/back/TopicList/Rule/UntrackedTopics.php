<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\Storage\Table\Forums;
use KeepersTeam\Webtlo\TopicList\Filter\Sort;
use KeepersTeam\Webtlo\TopicList\Formatter;
use KeepersTeam\Webtlo\TopicList\State;
use KeepersTeam\Webtlo\TopicList\Topic;
use KeepersTeam\Webtlo\TopicList\Topics;

/** Хранимые раздачи из других подразделов. */
final class UntrackedTopics implements ListInterface
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

        $getForumHeader = function(?int $id): string {
            $name  = $this->forums->getForumName(forumId: $id);
            $click = sprintf('addUnsavedSubsection(%s, "%s");', $id, $name);

            return "<div class='subsection-title'>$name <a href='#' onclick='$click' title='Нажмите, чтобы добавить подраздел в хранимые'>[$id]</a></div>";
        };

        // Типизируем данные раздач в объекты.
        $topics = array_map(function($topicData) {
            // Состояние раздачи в клиенте (пулька) [иконка, цвет, описание].
            $topicState = State::clientOnly(topicData: $topicData);

            return Topic::fromTopicData(topicData: $topicData, state: $topicState);
        }, $topics);

        $counter = new Topics();
        foreach ($topics as $topic) {
            ++$counter->count;
            $counter->size += $topic->size;

            if (!isset($counter->list[$topic->forumId])) {
                $counter->list[$topic->forumId] = $getForumHeader($topic->forumId);
            }

            // Выводим строку с данными раздачи.
            $counter->list[$topic->forumId] .= $this->output->formatTopic(topic: $topic);
        }

        natcasesort($counter->list);

        return $counter;
    }
}
