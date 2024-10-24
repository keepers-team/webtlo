<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients\Traits;

use KeepersTeam\Webtlo\Timers;

trait TopicIdSearch
{
    /**
     * @param array<string, mixed> $torrents
     *
     * @return array{}|array<string, mixed>
     */
    protected static function getEmptyTopics(array $torrents): array
    {
        return array_filter($torrents, fn($el) => empty($el['topic_id']));
    }

    /**
     * Пробуем найти раздачи в локальной таблице раздач хранимых подразделов.
     *
     * @param array<string, mixed> $torrents
     */
    protected function tryFillTopicIdFromTopics(array &$torrents): void
    {
        Timers::start('db_topics_search');

        $emptyHashed = self::getEmptyTopicsHashes($torrents);
        if (count($emptyHashed)) {
            $this->logger->debug('Start search torrents in Topics table', ['empty' => count($emptyHashed)]);

            $topics = $this->tableTopics->getTopicsIdsByHashes($emptyHashed);
            if (count($topics)) {
                $torrents = array_replace_recursive($torrents, $topics);
            }

            Timers::stash('db_topics_search');
            $this->logger->debug('End search torrents in Topics table', ['filled' => count($topics)]);
        }
    }

    /**
     * Пробуем найти раздачи в локальной таблице раздач в клиентах.
     *
     * @param array<string, mixed> $torrents
     */
    protected function tryFillTopicIdFromTorrents(array &$torrents): void
    {
        Timers::start('db_torrents_search');

        $emptyHashed = self::getEmptyTopicsHashes($torrents);
        if (count($emptyHashed)) {
            $this->logger->debug('Start search torrents in Torrents table', ['empty' => count($emptyHashed)]);

            $topics = $this->tableTorrents->getTopicsIdsByHashes($emptyHashed);
            if (count($topics)) {
                $torrents = array_replace_recursive($torrents, $topics);
            }

            Timers::stash('db_torrents_search');
            $this->logger->debug('End search torrents in Torrents table', ['filled' => count($topics)]);
        }
    }

    /**
     * @param array<string, mixed> $torrents
     *
     * @return array{}|string[]
     */
    private static function getEmptyTopicsHashes(array $torrents): array
    {
        $emptyTopics = self::getEmptyTopics($torrents);

        return array_map('strval', array_keys($emptyTopics));
    }
}
