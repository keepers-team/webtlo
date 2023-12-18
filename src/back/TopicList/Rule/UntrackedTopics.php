<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use KeepersTeam\Webtlo\Module\Forums;
use KeepersTeam\Webtlo\TopicList\Filter\Sort;
use KeepersTeam\Webtlo\TopicList\State;
use KeepersTeam\Webtlo\TopicList\Topic;
use KeepersTeam\Webtlo\TopicList\Topics;
use KeepersTeam\Webtlo\TopicList\Output;

/** Хранимые раздачи из других подразделов. */
final class UntrackedTopics implements ListInterface
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
        $statement = '
            SELECT
                TopicsUntracked.id AS topic_id,
                TopicsUntracked.hs AS info_hash,
                TopicsUntracked.na AS name,
                TopicsUntracked.si AS size,
                TopicsUntracked.rg AS reg_time,
                TopicsUntracked.ss AS forum_id,
                TopicsUntracked.se AS seed,
                -1 AS days_seed,
                Torrents.done,
                Torrents.paused,
                Torrents.error,
                Torrents.client_id
            FROM TopicsUntracked
            LEFT JOIN Torrents ON Torrents.info_hash = TopicsUntracked.hs
            WHERE TopicsUntracked.hs IS NOT NULL
        ';

        $topics = $this->selectSortedTopics($sort, $statement);

        $getForumHeader = function(int $id): string {
            $name  = Forums::getForumName($id);
            $click = sprintf('addUnsavedSubsection(%s, "%s");', $id, $name);

            return "<div class='subsection-title'>$name <a href='#' onclick='$click' title='Нажмите, чтобы добавить подраздел в хранимые'>[$id]</a></div>";
        };

        // Типизируем данные раздач в объекты.
        $topics = array_map(function($topicData) {
            // Состояние раздачи в клиенте (пулька) [иконка, цвет, описание].
            $topicState = State::clientOnly($topicData);

            return Topic::fromTopicData($topicData, $topicState);
        }, $topics);

        $counter = new Topics();
        foreach ($topics as $topic) {
            $counter->count++;
            $counter->size += $topic->size;

            if (!isset($counter->list[$topic->forumId])) {
                $counter->list[$topic->forumId] = $getForumHeader($topic->forumId);
            }

            // Выводим строку с данными раздачи.
            $counter->list[$topic->forumId] .= $this->output->formatTopic($topic);
        }

        natcasesort($counter->list);

        return $counter;
    }
}