<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\TopicList\Filter\Sort;
use KeepersTeam\Webtlo\TopicList\Formatter;
use KeepersTeam\Webtlo\TopicList\State;
use KeepersTeam\Webtlo\TopicList\Topic;
use KeepersTeam\Webtlo\TopicList\Topics;

/** Хранимые раздачи незарегистрированные на трекере. */
final class UnregisteredTopics implements ListInterface
{
    use FilterTrait;

    public function __construct(
        private readonly DB     $db,
        private readonly Formatter $output
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

        $counter = new Topics();
        foreach ($topics as $topicData) {
            $topicStatus = $topicData['status'];
            // Состояние раздачи в клиенте (пулька) [иконка, цвет, описание].
            $topicState = State::clientOnly(topicData: $topicData);

            $details = '';
            // Если имя раздачи отличается от имени в клиенте - выводим оба имени.
            if (!empty($topicData['prev']) && $topicData['prev'] !== $topicData['name']) {
                $details = sprintf('<span class="text-disabled">%s</span>', $topicData['prev']);
            }

            // Типизируем данные раздачи в объект.
            $topic = Topic::fromTopicData(topicData: $topicData, state: $topicState);
            unset($topicData);

            ++$counter->count;
            $counter->size += $topic->size;

            if (!isset($counter->list[$topicStatus])) {
                $counter->list[$topicStatus] = "<div class='subsection-title'>$topicStatus</div>";
            }

            // Выводим строку с данными раздачи.
            $counter->list[$topicStatus] .= $this->output->formatTopic(topic: $topic, details: $details);

            unset($topicStatus, $topicState, $topic, $details);
        }
        unset($topics);

        natcasesort($counter->list);

        return $counter;
    }
}
