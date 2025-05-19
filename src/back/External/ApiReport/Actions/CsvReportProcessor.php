<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use Closure;
use KeepersTeam\Webtlo\External\ApiReport\V1\KeeperTopics;
use KeepersTeam\Webtlo\External\ApiReport\V1\KeptTopic;
use League\Csv\Reader;

final class CsvReportProcessor implements ReportProcessorInterface
{
    use ReportProcessorTrait;

    /**
     * @param Reader<array<string, string>> $csv
     */
    public function __construct(
        private readonly Reader  $csv,
        private readonly Closure $seedingChecker
    ) {}

    public function process(): iterable
    {
        /** @var KeptTopic[][] $keepers */
        $keepers = [];

        foreach ($this->csv->getRecords() as $record) {
            $topic = $this->parseTopic(data: $record);
            if ($topic !== null) {
                $keepers[$record['user_id']][] = $topic;
            }
        }

        foreach ($keepers as $keeperId => $topics) {
            if (count($topics) === 0) {
                continue;
            }

            yield new KeeperTopics(
                keeperId   : (int) $keeperId,
                topicsCount: count($topics),
                topics     : $topics,
            );
        }
    }
}
