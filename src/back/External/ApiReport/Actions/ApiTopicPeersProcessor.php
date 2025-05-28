<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use KeepersTeam\Webtlo\External\Data\TopicPeers;

final class ApiTopicPeersProcessor implements TopicPeersProcessorInterface
{
    /**
     * @param string[]                 $columns
     * @param array<int, int|string>[] $releases
     */
    public function __construct(
        private readonly array $columns,
        private readonly array $releases,
    ) {}

    public function process(array $hashes): iterable
    {
        $hashIndex = array_flip($this->columns)['info_hash'];

        foreach ($this->releases as $release) {
            if (in_array($release[$hashIndex], $hashes, true)) {
                $topic = array_combine($this->columns, $release);

                yield new TopicPeers(
                    id      : (int) $topic['topic_id'],
                    hash    : (string) $topic['info_hash'],
                    seeders : (int) $topic['seeders'],
                    leechers: (int) $topic['leechers'],
                    keepers : (int) $topic['keeper_seeders'],
                );
            }
        }
    }
}
