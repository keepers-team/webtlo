<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module\Report;

use DateTimeInterface;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\Data\KeeperPermissions;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\WebTLO;

final class SendReport
{
    private bool $enabled = true;

    /**
     * @param ApiCredentials  $apiCredentials хранительские ключи
     * @param ApiReportClient $apiReport      подключение к API отчётов
     * @param WebTLO          $webtlo         основные параметры приложения
     */
    public function __construct(
        private readonly ApiCredentials  $apiCredentials,
        private readonly ApiReportClient $apiReport,
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
        ReportStatus      $statusRules,
        bool              $reportRewrite = false,
    ): array {
        // Устанавливаем статус подраздела.
        $this->apiReport->setForumStatus(
            forumId   : $forumId,
            status    : $statusRules->subForum,
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
            forumId     : $forumId,
            topicIds    : $downloadedTopics,
            status      : $statusRules->keptTopics,
            reportDate  : $reportDate,
            excludeOther: $reportRewrite,
        );
        if ($completeReport !== null) {
            $result['reportComplete'] = $completeReport;
        }

        // Отправляем отчёт о качаемых раздачах.
        if (count($downloadingTopics)) {
            $downloadingReport = $this->apiReport->reportKeptReleases(
                forumId   : $forumId,
                topicIds  : $downloadingTopics,
                status    : $statusRules->downloadingTopics,
                reportDate: $reportDate,
            );
            if ($downloadingReport !== null) {
                $result['reportDownloading'] = $downloadingReport;
            }
        }

        return $result;
    }

    /**
     * @param string[] $hashes
     *
     * @return array<string, mixed>
     */
    public function sendReportHashes(
        array             $hashes,
        DateTimeInterface $reportDate,
        int               $status,
        bool              $reportRewrite = false,
    ): array {
        $result = [
            'topics' => count($hashes),
        ];

        // Отправляем отчёт о скачанных раздачах.
        $report = $this->apiReport->reportKeptReleasesHashes(
            topicHashes : $hashes,
            status      : $status,
            reportDate  : $reportDate,
            excludeOther: $reportRewrite
        );
        if ($report !== null) {
            $result['status'] = $status;
            $result['result'] = $report;
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
    public function setForumsStatus(array $forumIds, ReportStatus $statusRules, bool $unsetOtherForums = false): array
    {
        return $this->apiReport->setForumsStatus(
            forumIds        : $forumIds,
            status          : $statusRules->subForum,
            appVersion      : $this->webtlo->appVersionLine(),
            unsetOtherForums: $unsetOtherForums
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function setForumsStatusAuto(): array
    {
        return $this->apiReport->setForumsStatusAuto();
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
}
