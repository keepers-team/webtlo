<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Data;

use DateTimeImmutable;
use Generator;

final class TopicsPeers
{
    /**
     * @param int                      $subForumId Ид подраздела
     * @param int                      $totalCount Количество раздач в подразделе
     * @param string[]                 $columns
     * @param array<int, int|string>[] $releases
     * @param DateTimeImmutable        $cacheTime  Дата хеширования ответа
     */
    public function __construct(
        public readonly int               $subForumId,
        public readonly int               $totalCount,
        public readonly DateTimeImmutable $cacheTime,
        private readonly array            $columns,
        private readonly array            $releases,
    ) {}

    /**
     * Из всего списка раздач, отфильтровать только нужные, по списку.
     *
     * @param string[] $hashes
     */
    public function filterReleases(array $hashes): Generator
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
