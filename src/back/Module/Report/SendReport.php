<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module\Report;

use DateTimeInterface;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\External\ApiReport\KeepingStatuses;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\External\ForumClient;
use KeepersTeam\Webtlo\WebTLO;

final class SendReport
{
    private bool $enabled = true;

    /**
     * @param ApiCredentials  $apiCredentials хранительские ключи
     * @param ApiReportClient $apiReport      подключение к API отчётов
     * @param ForumClient     $forumClient    подключение к форуму
     * @param WebTLO          $webtlo         основные параметры приложения
     */
    public function __construct(
        private readonly ApiCredentials  $apiCredentials,
        private readonly ApiReportClient $apiReport,
        private readonly ForumClient     $forumClient,
        private readonly WebTLO          $webtlo,
    ) {}

    public function checkApiAccess(): void
    {
        $this->setApiEnable($this->apiReport->checkAccess());
    }

    /**
     * @param array<string, mixed>[] $topicsToReport
     *
     * @return array<string, mixed>
     */
    public function sendForumTopics(
        int               $forumId,
        array             $topicsToReport,
        DateTimeInterface $reportDate,
        bool              $reportRewrite = false
    ): array {
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
            $reportRewrite,
        );
        if ($completeReport !== null) {
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
            if ($downloadingReport !== null) {
                $result['reportDownloading'] = $downloadingReport;
            }
        }

        return $result;
    }

    /**
     * @param int[] $forumIds
     *
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

    public function setApiEnable(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function isApiEnable(): bool
    {
        return $this->enabled;
    }

    /**
     * @param array<string, mixed> $apiCustom
     */
    public function sendCustomReport(array $apiCustom): void
    {
        $this->apiReport->sendCustomData($apiCustom);
    }

    public function checkForumAccess(): bool
    {
        return $this->forumClient->checkConnection();
    }

    public function sendForumSummaryReport(string $report): void
    {
        $this->forumClient->sendSummaryReport(userId: $this->apiCredentials->userId, message: $report);
    }
}
