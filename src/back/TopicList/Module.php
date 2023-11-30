<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

use Db;
use PDO;
use DateTimeImmutable;

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

        $topics = (array)Db::query_database($statement, [], true);
        $topics = Helper::topicsSortByFilter($topics, $filter);

        $forumsTitles = $this->getUntrackedForumTitles();

        $getForumHeader = function(int $id, string $name): string {
            $click = sprintf('addUnsavedSubsection(%s, "%s");', $id, $name);

            return "<div class='subsection-title'><a href='#' onclick='$click' title='Нажмите, чтобы добавить подраздел в хранимые'>($id)</a>$name</div>";
        };

        $dateImmutable  = new DateTimeImmutable();
        $preparedOutput = [];

        $filtered_topics_count = $filtered_topics_size = 0;
        // Перебираем раздачи.
        foreach ($topics as $topicData) {
            $filtered_topics_count++;
            $filtered_topics_size += $topicData['si'];

            $forumID = $topicData['ss'];

            if (!isset($preparedOutput[$forumID])) {
                $preparedOutput[$forumID] = $getForumHeader($forumID, $forumsTitles[$forumID]);
            }

            // Состояние раздачи в клиенте (пулька) [иконка, цвет, описание].
            $topicState = State::clientOnly($topicData);

            $topicObject = new Topic(
                $topicData['id'],
                $topicData['hs'],
                $topicData['na'],
                $topicData['si'],
                $dateImmutable->setTimestamp((int)$topicData['rg']),
                $topicData['se'],
                null,
                $topicState,
                $topicData['cl'] ?? null
            );

            // Выводим строку с данными раздачи.
            $preparedOutput[$forumID] .= $this->topicPattern->getFormatted($topicObject);

            unset($topicData, $forumID, $topicState, $topicObject);
        }
        unset($topics);

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

        $dateImmutable  = new DateTimeImmutable();
        $preparedOutput = [];

        $filtered_topics_count = $filtered_topics_size = 0;
        // Перебираем раздачи.
        foreach ($topics as $topicData) {
            $filtered_topics_count++;
            $filtered_topics_size += $topicData['total_size'];

            $topicStatus = $topicData['status'];

            if (!isset($preparedOutput[$topicStatus])) {
                $preparedOutput[$topicStatus] = "<div class='subsection-title'>$topicStatus</div>";
            }

            // Состояние раздачи в клиенте (пулька) [иконка, цвет, описание].
            $topicState = State::clientOnly($topicData);

            $topicObject = new Topic(
                $topicData['topic_id'],
                $topicData['info_hash'],
                $topicData['name'],
                $topicData['total_size'],
                $dateImmutable->setTimestamp((int)$topicData['time_added']),
                null,
                null,
                $topicState,
                $topicData['client_id'] ?? null
            );

            // Выводим строку с данными раздачи.
            $preparedOutput[$topicStatus] .= $this->topicPattern->getFormatted($topicObject);

            unset($topicData, $topicStatus, $topicState, $topicObject);
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

        $topics = (array)Db::query_database($statement, [], true);
        $topics = Helper::topicsSortByFilter($topics, $filter);

        $dateImmutable  = new DateTimeImmutable();
        $preparedOutput = [];

        $filtered_topics_count = $filtered_topics_size = 0;
        // Перебираем раздачи.
        foreach ($topics as $topicData) {
            $filtered_topics_count++;
            $filtered_topics_size += $topicData['si'];

            $forumID = $topicData['ss'];

            if (!isset($preparedOutput[$forumID])) {
                $preparedOutput[$forumID] =
                    "<div class='subsection-title'>{$this->cfg['subsections'][$forumID]['na']}</div>";
            }

            $topicObject = new Topic(
                $topicData['id'],
                $topicData['hs'],
                $topicData['na'],
                $topicData['si'],
                $dateImmutable->setTimestamp((int)$topicData['rg']),
                round($topicData['se'], 2)
            );

            // Выводим строку с данными раздачи.
            $preparedOutput[$forumID] .= $this->topicPattern->getFormatted($topicObject);

            unset($topicData, $forumID, $topicObject);
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
                Topics.pt,
                ' . implode(',', $statementFields) . '
            FROM Topics
                ' . implode(' ', $statementLeftJoin) . '
            WHERE Topics.hs IN (SELECT info_hash FROM Torrents GROUP BY info_hash HAVING count(1) > 1)
        ';

        $topics = (array)Db::query_database($statement, [], true);
        $topics = Helper::topicsSortByFilter($topics, $filter);

        $dateImmutable  = new DateTimeImmutable();
        $preparedOutput = [];

        $filtered_topics_count = $filtered_topics_size = 0;
        // Перебираем раздачи.
        foreach ($topics as $topicData) {
            $filtered_topics_count++;
            $filtered_topics_size += $topicData['si'];

            // Данные о клиентах, в которых есть найденные раздачи.
            $statement = '
                SELECT client_id, done, paused, error
                FROM Torrents
                WHERE info_hash = ?
                ORDER BY client_id
            ';

            $listTorrentClientsIDs = (array)Db::query_database(
                $statement,
                [$topicData['hs']],
                true
            );

            $listTorrentClientsNames = Helper::getFormattedClientsList($this->cfg['clients'] ?? [], $listTorrentClientsIDs);

            // Состояние раздачи в клиенте (пулька) [иконка, цвет, описание].
            $topicState = State::seedOnly(
                $averagePeriodFilter['seedPeriod'],
                $topicData['ds']
            );

            $topicObject = new Topic(
                $topicData['id'],
                $topicData['hs'],
                $topicData['na'],
                $topicData['si'],
                $dateImmutable->setTimestamp((int)$topicData['rg']),
                round($topicData['se'], 2),
                $topicData['pt'],
                $topicState,
            );

            // Выводим строку с данными раздачи.
            $preparedOutput[] = $this->topicPattern->getFormatted($topicObject, $listTorrentClientsNames);
        }

        return [$preparedOutput, $filtered_topics_count, $filtered_topics_size];
    }

    /** Получить список наименований не отслеживаемых подразделов */
    private function getUntrackedForumTitles(): array
    {
        return (array)Db::query_database(
            'SELECT id, name FROM Forums WHERE id IN (SELECT DISTINCT ss FROM TopicsUntracked)',
            [],
            true,
            PDO::FETCH_KEY_PAIR
        );
    }
}