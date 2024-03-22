<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Forum;

use KeepersTeam\Webtlo\External\ApiReport\KeepingStatuses;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\Timers;
use Psr\Log\LoggerInterface;

final class SendReport
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ApiReportClient $apiReport
    ) {
    }

    public function checkAccess(): bool
    {
        return $this->apiReport->checkAccess();
    }

    public function sendForumTopics(int $forumId, array $topicsToReport): array
    {
        // Разделяем раздачи на скачанные и качаемые.
        $downloadedTopics = $downloadingTopics = [];
        foreach ($topicsToReport as $topics) {
            if ($topics['done'] < 1.0) {
                $downloadingTopics[] = $topics['id'];
            } else {
                $downloadedTopics[] = $topics['id'];
            }
        }
        unset($topicsToReport);

        $createTime = Timers::getExecTime("create_api_$forumId");

        Timers::start("send_api_$forumId");

        // Отправляем отчёт о скачанных раздачах.
        $response = $this->apiReport->reportKeptReleases(
            $forumId,
            $downloadedTopics,
            KeepingStatuses::ReportedByApi->value,
            true
        );
        if (null !== $response) {
            $this->logger->debug('Reporting seeding', $response);
        }

        // Отправляем отчёт о качаемых раздачах.
        if (count($downloadingTopics)) {
            $response = $this->apiReport->reportKeptReleases(
                $forumId,
                $downloadingTopics,
                KeepingStatuses::ReportedByApi->value | KeepingStatuses::Downloading->value,
            );
            if (null !== $response) {
                $this->logger->debug('Reporting downloading', $response);
            }
        }

        return [
            'api'    => $forumId,
            'create' => $createTime,
            'send'   => Timers::getExecTime("send_api_$forumId"),
        ];
    }
}
