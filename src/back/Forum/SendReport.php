<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Forum;

use DateTimeInterface;
use KeepersTeam\Webtlo\External\ApiReport\KeepingStatuses;
use KeepersTeam\Webtlo\External\ApiReport\V1\ReportForumResponse;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\WebTLO;

final class SendReport
{
    private bool $enabled = true;

    public function __construct(
        private readonly ApiReportClient $apiReport,
        private readonly WebTLO          $webtlo,
    ) {
    }

    public function checkAccess(): void
    {
        $this->setEnable($this->apiReport->checkAccess());
    }

    /**
     * @param int                    $forumId
     * @param array<string, mixed>[] $topicsToReport
     * @param DateTimeInterface      $reportDate
     * @return array<string, mixed>
     */
    public function sendForumTopics(int $forumId, array $topicsToReport, DateTimeInterface $reportDate): array
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
            $reportDate,
            true,
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
                $reportDate,
            );
            if (null !== $downloadingReport) {
                $result['reportDownloading'] = $downloadingReport;
            }
        }

        return $result;
    }

    /**
     * @param int[] $forumIds
     * @param bool  $unsetOtherForums
     * @return array<string, mixed>
     */
    public function setForumsStatus(array $forumIds, bool $unsetOtherForums = false): array
    {
        return $this->apiReport->setForumsStatus(
            $forumIds,
            KeepingStatuses::ReportedByApi->value | KeepingStatuses::IgnoreNonReported->value,
            $this->webtlo->appVersionLine(),
            $unsetOtherForums
        );
    }

    public function setEnable(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function isEnable(): bool
    {
        return $this->enabled;
    }

    public function getReportTopics(): ReportForumResponse
    {
        $response = $this->apiReport->getForumsReportTopics();
        if ($response instanceof ReportForumResponse) {
            return $response;
        }

        return new ReportForumResponse([]);
    }

    /**
     * @param array<string, mixed> $apiCustom
     * @return void
     */
    public function sendCustomReport(array $apiCustom): void
    {
        $this->apiReport->sendCustomData($apiCustom);
    }
}
