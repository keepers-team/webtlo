<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\V1;

use DateTimeImmutable;

/** Данные раздач подраздела. */
final class ForumTopicsResponse
{
    public function __construct(
        public readonly DateTimeImmutable $updateTime,
        public readonly int               $totalSize,
        /** @var ForumTopic[] */
        public readonly array             $topics,
    ) {}
}
