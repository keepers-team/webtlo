<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module\Report;

use DateTimeInterface;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\Data\KeeperPermissions;
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
    ) {
        $this->apiCredentials->validate();
    }

    public function checkApiAccess(): void
    {
        $this->setApiEnable($this->apiReport->checkAccess());
    }

    /**
     * Ограничения доступа для кандидатов в хранители.
     */
    public function getKeeperPermissions(): KeeperPermissions
    {
        return $this->apiReport->getKeeperPermissions();
    }

    /**
     * Формирование и отправка списка хранимых раздач подраздела.
     *
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
            forumId   : $forumId,
            status    : KeepingStatuses::ReportedByApi->value | KeepingStatuses::IgnoreNonReported->value,
            appVersion: $this->webtlo->appVersionLine(),
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
            forumId             : $forumId,
            topicIds            : $downloadedTopics,
            status              : KeepingStatuses::ReportedByApi->value,
            reportDate          : $reportDate,
            excludeOtherReleases: $reportRewrite,
        );
        if ($completeReport !== null) {
            $result['reportComplete'] = $completeReport;
        }

        // Отправляем отчёт о качаемых раздачах.
        if (count($downloadingTopics)) {
            $downloadingReport = $this->apiReport->reportKeptReleases(
                forumId   : $forumId,
                topicIds  : $downloadingTopics,
                status    : KeepingStatuses::ReportedByApi->value | KeepingStatuses::Downloading->value,
                reportDate: $reportDate,
            );
            if ($downloadingReport !== null) {
                $result['reportDownloading'] = $downloadingReport;
            }
        }

        return $result;
    }

    /**
     * Отмечаем в API подразделы как хранимые.
     * И снятие отметки с прочих подразделов.
     *
     * @param int[] $forumIds
     * @param bool  $unsetOtherForums - снять отметку хранения, если true
     *
     * @return array<string, mixed>
     */
    public function setForumsStatus(array $forumIds, bool $unsetOtherForums = false): array
    {
        return $this->apiReport->setForumsStatus(
            forumIds        : $forumIds,
            status          : KeepingStatuses::ReportedByApi->value | KeepingStatuses::IgnoreNonReported->value,
            appVersion      : $this->webtlo->appVersionLine(),
            unsetOtherForums: $unsetOtherForums
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
