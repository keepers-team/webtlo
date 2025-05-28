<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use KeepersTeam\Webtlo\External\Data\TopicPeers;
use League\Csv\Reader;

final class CsvTopicPeersProcessor implements TopicPeersProcessorInterface
{
    /**
     * @param Reader<array<string, string>> $csv
     */
    public function __construct(
        private readonly Reader $csv
    ) {}

    public function process(array $hashes): iterable
    {
        foreach ($this->csv->getRecords() as $topic) {
            $topicHash = (string) $topic['info_hash'];

            if (in_array($topicHash, $hashes, true)) {
                yield new TopicPeers(
                    id      : (int) $topic['topic_id'],
                    hash    : $topicHash,
                    seeders : (int) $topic['seeders'],
                    leechers: (int) $topic['leechers'],
                    keepers : (int) $topic['keeper_seeders'],
                );
            }
        }
    }
}
