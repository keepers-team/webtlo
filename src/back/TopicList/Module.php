<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

use KeepersTeam\Webtlo\Module\Forums;
use Db;

final class Module
{
    public function __construct(
        private readonly array        $cfg,
        private readonly TopicPattern $topicPattern
    ) {
    }

    /** Хранимые раздачи из других подразделов. */
    public function getUntrackedTopics(array $filter): array
    {
        $statement = '
            SELECT
                TopicsUntracked.id,
                TopicsUntracked.hs,
                TopicsUntracked.na,
                TopicsUntracked.si,
                TopicsUntracked.rg,
                TopicsUntracked.ss,
                TopicsUntracked.se,
                -1 AS ds,
                Torrents.done,
                Torrents.paused,
                Torrents.error,
                Torrents.client_id as cl
            FROM TopicsUntracked
            LEFT JOIN Torrents ON Torrents.info_hash = TopicsUntracked.hs
            WHERE TopicsUntracked.hs IS NOT NULL
        ';

        $topics = $this->selectSortedTopics($statement, $filter);

        $getForumHeader = function(int $id): string {
            $name  = Forums::getForumName($id);
            $click = sprintf('addUnsavedSubsection(%s, "%s");', $id, $name);

            return "<div class='subsection-title'>$name <a href='#' onclick='$click' title='Нажмите, чтобы добавить подраздел в хранимые'>[$id]</a></div>";
        };

        $preparedOutput = [];

        $filtered_topics_count = $filtered_topics_size = 0;
        foreach ($topics as $topicData) {
            // Состояние раздачи в клиенте (пулька) [иконка, цвет, описание].
            $topicState = State::clientOnly($topicData);

            // Типизируем данные раздачи в объект.
            $topic = new Topic(
                $topicData['id'],
                $topicData['hs'],
                $topicData['na'],
                $topicData['si'],
                Helper::setTimestamp((int)$topicData['rg']),
                $topicData['ss'],
                $topicData['se'],
                null,
                $topicState,
                $topicData['cl'] ?? null
            );
            unset($topicData);

            $filtered_topics_count++;
            $filtered_topics_size += $topic->size;

            if (!isset($preparedOutput[$topic->forumId])) {
                $preparedOutput[$topic->forumId] = $getForumHeader($topic->forumId);
            }

            // Выводим строку с данными раздачи.
            $preparedOutput[$topic->forumId] .= $this->topicPattern->getFormatted($topic);
        }

        natcasesort($preparedOutput);

        return [$preparedOutput, $filtered_topics_count, $filtered_topics_size];
    }

    /** Хранимые раздачи незарегистрированные на трекере. */
    public function getUnregisteredTopics(): array
    {
        $statement = "
            SELECT
                Torrents.topic_id,
                CASE WHEN TopicsUnregistered.name IS '' OR TopicsUnregistered.name IS NULL THEN Torrents.name ELSE TopicsUnregistered.name END as name,
                TopicsUnregistered.status,
                Torrents.info_hash,
                Torrents.client_id,
                Torrents.total_size,
                Torrents.time_added,
                Torrents.paused,
                Torrents.error,
                Torrents.done
            FROM TopicsUnregistered
            LEFT JOIN Torrents ON TopicsUnregistered.info_hash = Torrents.info_hash
            WHERE TopicsUnregistered.info_hash IS NOT NULL
            ORDER BY TopicsUnregistered.name
        ";

        $topics = (array)Db::query_database($statement, [], true);

        $preparedOutput = [];

        $filtered_topics_count = $filtered_topics_size = 0;
        foreach ($topics as $topicData) {
            // Состояние раздачи в клиенте (пулька) [иконка, цвет, описание].
            $topicState = State::clientOnly($topicData);

            // Типизируем данные раздачи в объект.
            $topic = new Topic(
                $topicData['topic_id'],
                $topicData['info_hash'],
                $topicData['name'],
                $topicData['total_size'],
                Helper::setTimestamp((int)$topicData['time_added']),
                null,
                null,
                null,
                $topicState,
                $topicData['client_id'] ?? null
            );

            $filtered_topics_count++;
            $filtered_topics_size += $topic->size;

            $topicStatus = $topicData['status'];
            if (!isset($preparedOutput[$topicStatus])) {
                $preparedOutput[$topicStatus] = "<div class='subsection-title'>$topicStatus</div>";
            }

            // Выводим строку с данными раздачи.
            $preparedOutput[$topicStatus] .= $this->topicPattern->getFormatted($topic);

            unset($topicData, $topicStatus, $topicState, $topic);
        }
        unset($topics);

        natcasesort($preparedOutput);

        return [$preparedOutput, $filtered_topics_count, $filtered_topics_size];
    }

    /** Раздачи из "Черного списка". */
    public function getBlackListedTopics(array $filter): array
    {
        $statement = sprintf(
            "
                SELECT
                    Topics.id,
                    Topics.hs,
                    Topics.ss,
                    Topics.na,
                    Topics.si,
                    Topics.rg,
                    %s,
                    TopicsExcluded.comment
                FROM Topics
                LEFT JOIN TopicsExcluded ON Topics.hs = TopicsExcluded.info_hash
                WHERE TopicsExcluded.info_hash IS NOT NULL
            ",
            $this->cfg['avg_seeders'] ? '(se * 1.) / qt as se' : 'se'
        );

        $topics = $this->selectSortedTopics($statement, $filter);

        $preparedOutput = [];

        $filtered_topics_count = $filtered_topics_size = 0;
        foreach ($topics as $topicData) {
            // Типизируем данные раздачи в объект.
            $topic = new Topic(
                $topicData['id'],
                $topicData['hs'],
                $topicData['na'],
                $topicData['si'],
                Helper::setTimestamp((int)$topicData['rg']),
                $topicData['ss'],
                round($topicData['se'], 2)
            );
            unset($topicData);

            $filtered_topics_count++;
            $filtered_topics_size += $topic->size;

            if (!isset($preparedOutput[$topic->forumId])) {
                $preparedOutput[$topic->forumId] = sprintf(
                    "<div class='subsection-title'>%s [%d]</div>",
                    Forums::getForumName($topic->forumId),
                    $topic->forumId,
                );
            }

            // Выводим строку с данными раздачи.
            $preparedOutput[$topic->forumId] .= $this->topicPattern->getFormatted($topic);
        }
        unset($topics);

        natcasesort($preparedOutput);

        return [$preparedOutput, $filtered_topics_count, $filtered_topics_size];
    }

    /** Хранимые дублирующиеся раздачи. */
    public function getDuplicatedTopics(array $filter, array $averagePeriodFilter): array
    {
        // Учитываем данные по средним сидам.
        [$statementFields, $statementLeftJoin] = prepareAverageQueryParam($averagePeriodFilter);

        $statement = '
            SELECT
                Topics.id,
                Topics.hs,
                Topics.na,
                Topics.si,
                Topics.rg,
                Topics.ss,
                Topics.pt,
                ' . implode(',', $statementFields) . '
            FROM Topics
                ' . implode(' ', $statementLeftJoin) . '
            WHERE Topics.hs IN (SELECT info_hash FROM Torrents GROUP BY info_hash HAVING count(1) > 1)
        ';

        $topics = $this->selectSortedTopics($statement, $filter);

        $preparedOutput = [];

        $filtered_topics_count = $filtered_topics_size = 0;
        // Перебираем раздачи.
        foreach ($topics as $topicData) {
            // Состояние раздачи в клиенте (пулька) [иконка, цвет, описание].
            $topicState = State::seedOnly(
                $averagePeriodFilter['seedPeriod'],
                $topicData['ds']
            );

            // Типизируем данные раздачи в объект.
            $topic = new Topic(
                $topicData['id'],
                $topicData['hs'],
                $topicData['na'],
                $topicData['si'],
                Helper::setTimestamp((int)$topicData['rg']),
                $topicData['ss'],
                round($topicData['se'], 2),
                $topicData['pt'],
                $topicState,
            );
            unset($topicData);

            $filtered_topics_count++;
            $filtered_topics_size += $topic->size;

            // Данные о клиентах, в которых есть найденные раздачи.
            $statement = '
                SELECT client_id, done, paused, error
                FROM Torrents
                WHERE info_hash = ?
                ORDER BY client_id
            ';

            $listTorrentClientsIDs = (array)Db::query_database(
                $statement,
                [$topic->hash],
                true
            );

            $listTorrentClientsNames = Helper::getFormattedClientsList($this->cfg['clients'] ?? [], $listTorrentClientsIDs);

            // Выводим строку с данными раздачи.
            $preparedOutput[] = $this->topicPattern->getFormatted($topic, $listTorrentClientsNames);
        }

        return [$preparedOutput, $filtered_topics_count, $filtered_topics_size];
    }

    /** Получить из БД список раздач и отсортировать по заданному фильтру. */
    private function selectSortedTopics(string $statement, array $filter): array
    {
        $topics = (array)Db::query_database($statement, [], true);

        return Helper::topicsSortByFilter($topics, $filter);
    }
}