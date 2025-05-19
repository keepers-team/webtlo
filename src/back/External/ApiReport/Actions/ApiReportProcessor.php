<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use Closure;
use KeepersTeam\Webtlo\External\ApiReport\V1\KeeperTopics;

final class ApiReportProcessor implements ReportProcessorInterface
{
    use ReportProcessorTrait;

    /**
     * @param list<array<string, mixed>> $reports
     */
    public function __construct(
        private readonly array   $reports,
        private readonly Closure $seedingChecker
    ) {}

    public function process(): iterable
    {
        foreach ($this->reports as $keeper) {
            if ($keeper['total_count'] <= 0) {
                continue;
            }

            $columns = $keeper['columns'];
            $topics  = [];

            foreach ($keeper['kept_releases'] as $release) {
                $topic = $this->parseTopic(array_combine($columns, $release));
                if ($topic !== null) {
                    $topics[] = $topic;
                }
            }

            if (count($topics)) {
                yield new KeeperTopics(
                    keeperId   : (int) $keeper['keeper_id'],
                    topicsCount: count($topics),
                    topics     : $topics,
                );
            }
        }
    }
}
