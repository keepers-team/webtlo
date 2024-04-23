<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\ApiReport\V1\ReportForumResponse;
use KeepersTeam\Webtlo\External\ApiReport\V1\ReportForumTopic;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

trait ReportForumTopics
{
    /**
     * @return ReportForumResponse|ApiError
     */
    public function getForumsReportTopics(): ReportForumResponse|ApiError
    {
        $dataProcessor = self::getReportTopicsProcessor($this->logger);
        try {
            $response = $this->client->get(uri: 'subforum/report_topics');
        } catch (GuzzleException $error) {
            $code = $error->getCode();

            return ApiError::fromHttpCode(code: $code);
        }

        return $dataProcessor($response);
    }

    private static function getReportTopicsProcessor(LoggerInterface $logger): callable
    {
        return function(ResponseInterface $response) use (&$logger): ReportForumResponse|ApiError {
            $result = self::decodeResponse($logger, $response);
            if ($result instanceof ApiError) {
                return $result;
            }

            return self::parseStaticReportTopic($result);
        };
    }

    /**
     * @param array<int, mixed> $result
     * @return ReportForumResponse
     */
    private static function parseStaticReportTopic(array $result): ReportForumResponse
    {
        $result = array_filter($result);

        $reportTopics = [];
        foreach ($result as $forumId => $topicId) {
            $reportTopics[$forumId] = new ReportForumTopic($forumId, $topicId);
        }

        return new ReportForumResponse($reportTopics);
    }
}
