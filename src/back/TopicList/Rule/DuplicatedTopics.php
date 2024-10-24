<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\TopicList\Filter\Sort;
use KeepersTeam\Webtlo\TopicList\Helper;
use KeepersTeam\Webtlo\TopicList\Output;
use KeepersTeam\Webtlo\TopicList\State;
use KeepersTeam\Webtlo\TopicList\Topic;
use KeepersTeam\Webtlo\TopicList\Topics;
use KeepersTeam\Webtlo\TopicList\Validate;
use KeepersTeam\Webtlo\TopicList\ValidationException;

final class DuplicatedTopics implements ListInterface
{
    use DbHelperTrait;
    use FilterTrait;

    public function __construct(
        private readonly DB     $db,
        /** @var array<string, mixed> */
        private readonly array  $cfg,
        private readonly Output $output
    ) {}

    /**
     * @throws ValidationException
     */
    public function getTopics(array $filter, Sort $sort): Topics
    {
        // Данные для фильтрации по средним сидам.
        $seedFilter = Validate::prepareAverageSeedFilter($filter, $this->cfg);

        $statement = "
            SELECT
                Topics.id AS topic_id,
                Topics.info_hash,
                Topics.name AS name,
                Topics.size AS size,
                Topics.reg_time AS reg_time,
                Topics.forum_id,
                Topics.keeping_priority AS priority,
                0 AS client_id,
                {$seedFilter->getFields()}
            FROM Topics
                {$seedFilter->getJoins()}
            WHERE Topics.info_hash IN (SELECT info_hash FROM Torrents GROUP BY info_hash HAVING count(1) > 1)
            ORDER BY {$sort->fieldDirection()}
        ";

        $topics = $this->selectTopics($statement);

        // Типизируем данные раздач в объекты.
        $topics = array_map(function($topicData) use ($seedFilter) {
            // Состояние раздачи в клиенте (пулька) [иконка, цвет, описание].
            $topicState = State::seedOnly(
                $seedFilter->seedPeriod,
                (int) $topicData['days_seed']
            );

            return Topic::fromTopicData($topicData, $topicState);
        }, $topics);

        // Данные о клиентах, в которых есть найденные раздачи.
        $torrentClients = $this->getClientsByHashes(
            KeysObject::create(
                array_column($topics, 'hash')
            )
        );

        $counter = new Topics();
        foreach ($topics as $topic) {
            $counter->count++;
            $counter->size += $topic->size;

            // Выводим строку с данными раздачи.
            $counter->list[] = $this->output->formatTopic(
                $topic,
                Helper::getFormattedClientsList(
                    $this->cfg['clients'] ?? [],
                    $torrentClients[$topic->hash] ?? []
                )
            );
        }

        return $counter;
    }

    /**
     * Список клиентов, в которых хранятся заданные раздачи.
     *
     * @param KeysObject $hashes
     * @return array<int|string, mixed>[]
     */
    private function getClientsByHashes(KeysObject $hashes): array
    {
        $statement = "
            SELECT info_hash, client_id, done, paused, error
            FROM Torrents
            WHERE info_hash IN ($hashes->keys)
            ORDER BY client_id
        ";

        return $this->queryStatementGroup($statement, $hashes->values);
    }
}
