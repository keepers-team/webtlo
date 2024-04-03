<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\Actions;

use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\HighPriorityTopic;
use KeepersTeam\Webtlo\External\Api\V1\HighPriorityTopicsResponse;
use KeepersTeam\Webtlo\External\Api\V1\TorrentStatus;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

trait HighPriorityTopics
{
    use Processor;

    /**
     * Получить список раздач, с высоким приоритетом.
     */
    public function getTopicsHighPriority(): HighPriorityTopicsResponse|ApiError
    {
        $dataProcessor = self::getHighPriorityTopicProcessor($this->logger);
        try {
            $response = $this->client->get(uri: 'static/pvc/high_priority_topics.json.gz');
        } catch (GuzzleException $error) {
            $code = $error->getCode();

            return ApiError::fromHttpCode($code);
        }

        return $dataProcessor($response);
    }

    protected static function getHighPriorityTopicProcessor(LoggerInterface $logger): callable
    {
        return function(ResponseInterface $response) use (&$logger): HighPriorityTopicsResponse|ApiError {
            $result = self::decodeResponse($logger, $response);
            if ($result instanceof ApiError) {
                return $result;
            }

            $format = $result['format']['topic_id'];

            $topics = array_map(
                [self::class, 'parseStaticHighPriorityTopic'],
                array_keys($result['result']),
                array_map(fn($val) => array_combine($format, $val), array_values($result['result'])),
            );

            return new HighPriorityTopicsResponse(
                updateTime: self::dateTimeFromTimestamp($result['update_time']),
                totalSize : array_sum(array_column($topics, 'size')),
                topics    : $topics,
            );
        };
    }

    /**
     * @param string                    $topicId
     * @param array<string, int|string> $payload
     * @return HighPriorityTopic
     */
    private static function parseStaticHighPriorityTopic(string $topicId, array $payload): HighPriorityTopic
    {
        return new HighPriorityTopic(
            id        : (int)$topicId,
            status    : TorrentStatus::from((int)$payload['tor_status']),
            seeders   : (int)$payload['seeders'],
            registered: self::dateTimeFromTimestamp((int)$payload['reg_time']),
            size      : (int)$payload['tor_size_bytes'],
            forumId   : (int)$payload['forum_id']
        );
    }
}
