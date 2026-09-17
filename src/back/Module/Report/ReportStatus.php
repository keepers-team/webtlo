<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module\Report;

/**
 * Набор статусов, которые будут переданы в API с отчётами.
 */
final class ReportStatus
{
    /**
     * @param non-negative-int $subForum
     * @param non-negative-int $keptTopics
     * @param non-negative-int $downloadingTopics
     */
    public function __construct(
        public readonly int $subForum,
        public readonly int $keptTopics,
        public readonly int $downloadingTopics,
    ) {}
}
