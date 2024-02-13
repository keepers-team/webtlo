<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\V1;

use DateTimeImmutable;

/** Данные всех раздач с высоким приоритетом хранения.  */
final class HighPriorityTopicsResponse
{
    public function __construct(
        public readonly DateTimeImmutable $updateTime,
        public readonly int               $totalSize,
        /** @var HighPriorityTopic[] */
        public readonly array             $topics,
    ) {
    }
}
