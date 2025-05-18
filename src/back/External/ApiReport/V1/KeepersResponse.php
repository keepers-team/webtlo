<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\V1;

/**
 * Ответ API с информацией о хранителях раздач.
 *
 * @property iterable<KeeperTopics> $keepers Генератор или массив с данными хранителей
 */
final class KeepersResponse
{
    /**
     * @param int                    $forumId ID подраздела
     * @param iterable<KeeperTopics> $keepers Генератор или массив с данными хранителей
     */
    public function __construct(
        public readonly int      $forumId,
        public readonly iterable $keepers,
    ) {}
}
