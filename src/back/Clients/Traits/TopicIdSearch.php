<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients\Traits;

use KeepersTeam\Webtlo\Module\Topics;
use KeepersTeam\Webtlo\Module\Torrents;
use KeepersTeam\Webtlo\Timers;

trait TopicIdSearch
{
    /**
     * @param array<string, mixed> $torrents
     * @return array{}|array<string, mixed>
     */
    protected static function getEmptyTopics(array $torrents): array
    {
        return array_filter($torrents, fn($el) => empty($el['topic_id']));
    }

    /**
     * @param array<string, mixed> $torrents
     * @return array{}|string[]
     */
    protected static function getEmptyTopicsHashes(array $torrents): array
    {
        $emptyTopics = self::getEmptyTopics($torrents);

        return array_map('strval', array_keys($emptyTopics));
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

            $topics = Topics::getTopicsIdsByHashes($emptyHashed);
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

            $topics = Torrents::getTopicsIdsByHashes($emptyHashed);
            if (count($topics)) {
                $torrents = array_replace_recursive($torrents, $topics);
            }

            Timers::stash('db_torrents_search');
            $this->logger->debug('End search torrents in Torrents table', ['filled' => count($topics)]);
        }
    }
}
