<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use KeepersTeam\Webtlo\Infrastructure\Database\ConnectionInterface;
use KeepersTeam\Webtlo\Storage\KeysObject;
use KeepersTeam\Webtlo\TopicList\ConfigFilter;
use KeepersTeam\Webtlo\TopicList\Filter\Sort;
use KeepersTeam\Webtlo\TopicList\State;
use KeepersTeam\Webtlo\TopicList\Topic;
use KeepersTeam\Webtlo\TopicList\TopicGroup;
use KeepersTeam\Webtlo\TopicList\TopicResult;
use KeepersTeam\Webtlo\TopicList\Topics;
use KeepersTeam\Webtlo\TopicList\Validate;
use KeepersTeam\Webtlo\TopicList\ValidationException;

final class DuplicatedTopics implements ListInterface
{
    use DbHelperTrait;
    use FilterTrait;

    public function __construct(
        private readonly ConnectionInterface $con,
        private readonly ConfigFilter        $configFilter,
    ) {}

    /**
     * @throws ValidationException
     */
    public function getTopics(array $filter, Sort $sort): Topics
    {
        // Данные для фильтрации по средним сидам.
        $seedFilter = Validate::prepareAverageSeedFilter(
            filter        : $filter,
            averageEnabled: $this->configFilter->enableAverageHistory,
        );

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

        $topics = $this->selectTopics(statement: $statement);

        // Данные о клиентах, в которых есть найденные раздачи.
        $torrentClients = $this->getClientsByHashes(
            hashes: KeysObject::create(
                data: array_column($topics, 'info_hash')
            )
        );

        $results = [];
        foreach ($topics as $topicData) {
            // Состояние раздачи в клиенте (пулька) [иконка, цвет, описание].
            $topicState = State::seedOnly(
                daysRequire: $seedFilter->seedPeriod,
                daysUpdate : (int) $topicData['days_seed']
            );

            $topic = Topic::fromTopicData(topicData: $topicData, state: $topicState);
            unset($topicData);

            $results[] = new TopicResult(
                topic: $topic,
                details: [
                    'clients' => $torrentClients[$topic->hash] ?? [],
                ],
            );
        }

        return new Topics(groups: [
            TopicGroup::makeDefault(topics: $results),
        ]);
    }

    /**
     * Список клиентов, в которых хранятся заданные раздачи.
     *
     * @return array<array-key, mixed>[]
     */
    private function getClientsByHashes(KeysObject $hashes): array
    {
        $statement = "
            SELECT info_hash, client_id, done, paused, error
            FROM Torrents
            WHERE info_hash IN ($hashes->keys)
            ORDER BY client_id
        ";

        return $this->queryStatementGroup(statement: $statement, params: $hashes->values);
    }
}
