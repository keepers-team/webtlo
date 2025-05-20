<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\V1;

use DateTimeImmutable;

/** Данные раздач подраздела. */
final class ForumTopicsResponse
{
    /**
     * @param iterable<ForumTopic[]> $topicsChunks генератор для ленивой обработки раздач, по 500шт
     */
    public function __construct(
        public readonly DateTimeImmutable $updateTime,
        public readonly int               $totalCount,
        public readonly int               $totalSize,
        public readonly iterable          $topicsChunks,
    ) {}
}
