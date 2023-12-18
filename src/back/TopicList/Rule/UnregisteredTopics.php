<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use KeepersTeam\Webtlo\TopicList\Filter\Sort;
use KeepersTeam\Webtlo\TopicList\State;
use KeepersTeam\Webtlo\TopicList\Topic;
use KeepersTeam\Webtlo\TopicList\Topics;
use KeepersTeam\Webtlo\TopicList\Output;

/** Хранимые раздачи незарегистрированные на трекере. */
final class UnregisteredTopics implements ListInterface
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
        $statement = "
            SELECT
                Torrents.topic_id,
                CASE WHEN TopicsUnregistered.name IS '' OR TopicsUnregistered.name IS NULL THEN Torrents.name ELSE TopicsUnregistered.name END AS name,
                TopicsUnregistered.status,
                Torrents.info_hash,
                Torrents.total_size AS size,
                Torrents.time_added AS reg_time,
                -1 AS seed,
                -1 AS days_seed,
                Torrents.client_id,
                Torrents.paused,
                Torrents.error,
                Torrents.done
            FROM TopicsUnregistered
            LEFT JOIN Torrents ON TopicsUnregistered.info_hash = Torrents.info_hash
            WHERE TopicsUnregistered.info_hash IS NOT NULL
            ORDER BY TopicsUnregistered.name
        ";

        $topics = $this->selectSortedTopics($sort, $statement);

        $counter = new Topics();
        foreach ($topics as $topicData) {
            $topicStatus = $topicData['status'];
            // Состояние раздачи в клиенте (пулька) [иконка, цвет, описание].
            $topicState = State::clientOnly($topicData);

            // Типизируем данные раздачи в объект.
            $topic = Topic::fromTopicData($topicData, $topicState);
            unset($topicData);

            $counter->count++;
            $counter->size += $topic->size;

            if (!isset($counter->list[$topicStatus])) {
                $counter->list[$topicStatus] = "<div class='subsection-title'>$topicStatus</div>";
            }

            // Выводим строку с данными раздачи.
            $counter->list[$topicStatus] .= $this->output->formatTopic($topic);

            unset($topicStatus, $topicState, $topic);
        }
        unset($topics);

        natcasesort($counter->list);

        return $counter;
    }
}