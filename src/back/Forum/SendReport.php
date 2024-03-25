<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Forum;

use KeepersTeam\Webtlo\External\ApiReport\KeepingStatuses;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\WebTLO;
use Psr\Log\LoggerInterface;

final class SendReport
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ApiReportClient $apiReport,
        private readonly WebTLO          $webtlo,
    ) {
    }

    public function checkAccess(): bool
    {
        return $this->apiReport->checkAccess();
    }

    public function sendForumTopics(int $forumId, array $topicsToReport): array
    {
        // Устанавливаем статус подраздела.
        $this->apiReport->setForumStatus(
            $forumId,
            KeepingStatuses::ReportedByApi->value | KeepingStatuses::IgnoreNonReported->value,
            $this->webtlo->appVersionLine(),
        );

        $result = [
            'forumId' => $forumId,
            'topics'  => count($topicsToReport),
        ];

        // Разделяем раздачи на скачанные и качаемые.
        $downloadedTopics = $downloadingTopics = [];
        foreach ($topicsToReport as $topic) {
            if ($topic['done'] < 1.0) {
                $downloadingTopics[] = $topic['id'];
            } else {
                $downloadedTopics[] = $topic['id'];
            }
        }
        unset($topicsToReport);

        // Отправляем отчёт о скачанных раздачах.
        $completeReport = $this->apiReport->reportKeptReleases(
            $forumId,
            $downloadedTopics,
            KeepingStatuses::ReportedByApi->value,
            true
        );
        if (null !== $completeReport) {
            $result['reportComplete'] = $completeReport;
        }

        // Отправляем отчёт о качаемых раздачах.
        if (count($downloadingTopics)) {
            $downloadingReport = $this->apiReport->reportKeptReleases(
                $forumId,
                $downloadingTopics,
                KeepingStatuses::ReportedByApi->value | KeepingStatuses::Downloading->value,
            );
            if (null !== $downloadingReport) {
                $result['reportDownloading'] = $downloadingReport;
            }
        }

        return $result;
    }
}
