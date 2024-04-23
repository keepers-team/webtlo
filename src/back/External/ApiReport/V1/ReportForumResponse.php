<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\V1;

/**
 * Список связей ид подраздела и ид темы со списками на форуме.
 */
final class ReportForumResponse
{
    /**
     * @param array<int, ReportForumTopic> $reportForumTopics
     */
    public function __construct(public readonly array $reportForumTopics)
    {
    }

    public function getReportTopicId(int $forumId): ?int
    {
        $report = $this->reportForumTopics[$forumId] ?? null;

        return $report?->topicId;
    }
}
