<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\TopicList\DbHelper;
use KeepersTeam\Webtlo\TopicList\Filter\Sort;
use KeepersTeam\Webtlo\TopicList\Helper;
use KeepersTeam\Webtlo\TopicList\Validate;
use KeepersTeam\Webtlo\TopicList\State;
use KeepersTeam\Webtlo\TopicList\Topic;
use KeepersTeam\Webtlo\TopicList\Topics;
use KeepersTeam\Webtlo\TopicList\Output;
use Exception;

final class DuplicatedTopics implements ListInterface
{
    use FilterTrait;

    public function __construct(
        private readonly array  $cfg,
        private readonly Output $output
    ) {
    }

    /**
     * @throws Exception
     */
    public function getTopics(array $filter, Sort $sort): Topics
    {
        // Данные для фильтрации по средним сидам.
        $seedFilter = Validate::prepareAverageSeedFilter($filter, $this->cfg);

        $statement = '
            SELECT
                Topics.id AS topic_id,
                Topics.hs AS info_hash,
                Topics.na AS name,
                Topics.si AS size,
                Topics.rg AS reg_time,
                Topics.ss AS forum_id,
                Topics.pt AS priority,
                0 AS client_id,
                ' . implode(',', $seedFilter->fields) . '
            FROM Topics
                ' . implode(' ', $seedFilter->joins) . '
            WHERE Topics.hs IN (SELECT info_hash FROM Torrents GROUP BY info_hash HAVING count(1) > 1)
        ';

        $topics = $this->selectSortedTopics($sort, $statement);

        // Типизируем данные раздач в объекты.
        $topics = array_map(function($topicData) use ($seedFilter) {
            // Состояние раздачи в клиенте (пулька) [иконка, цвет, описание].
            $topicState = State::seedOnly(
                $seedFilter->seedPeriod,
                (int)$topicData['days_seed']
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

    /** Список клиентов, в которых хранятся заданные раздачи. */
    private function getClientsByHashes(KeysObject $hashes): array
    {
        $statement = "
            SELECT info_hash, client_id, done, paused, error
            FROM Torrents
            WHERE info_hash IN ($hashes->keys)
            ORDER BY client_id
        ";

        return DbHelper::queryStatementGroup($statement, $hashes->values);
    }
}