<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\V1;

/**
 * Связь ид подраздела и ид темы со списками на форуме.
 */
final class ReportForumTopic
{
    public function __construct(
        public readonly int $forumId,
        public readonly int $topicId
    ) {
    }
}
