<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\Actions;

use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\ForumTopic;
use KeepersTeam\Webtlo\External\Api\V1\ForumTopicsResponse;
use KeepersTeam\Webtlo\External\Api\V1\KeepingPriority;
use KeepersTeam\Webtlo\External\Api\V1\TorrentStatus;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

trait ForumTopics
{
    use Processor;

    /**
     * Получить список раздач подраздела.
     */
    public function getForumTopicsData(int $forumId): ForumTopicsResponse|ApiError
    {
        $dataProcessor = self::getForumTopicsProcessor($this->logger, $forumId);
        try {
            $response = $this->client->get(
                uri: sprintf('static/pvc/f/%d', $forumId)
            );
        } catch (GuzzleException $error) {
            $code = $error->getCode();

            return ApiError::fromHttpCode($code);
        }

        return $dataProcessor($response);
    }

    private static function getForumTopicsProcessor(LoggerInterface $logger, int $forumId): callable
    {
        return function(ResponseInterface $response) use (&$logger, $forumId): ForumTopicsResponse|ApiError {
            $result = self::decodeResponse($logger, $response);
            if ($result instanceof ApiError) {
                return $result;
            }

            $format = $result['format']['topic_id'];

            $topics = [];
            foreach ($result['result'] as $id => $data) {
                $payload  = array_combine($format, $data);
                $topics[] = self::parseStaticForumTopics($forumId, (int)$id, $payload);
            }

            return new ForumTopicsResponse(
                updateTime: self::dateTimeFromTimestamp($result['update_time']),
                totalSize : $result['total_size_bytes'],
                topics    : $topics
            );
        };
    }

    /**
     * @param int                  $forumId
     * @param int                  $topicId
     * @param array<string, mixed> $payload
     * @return ForumTopic
     */
    private static function parseStaticForumTopics(int $forumId, int $topicId, array $payload): ForumTopic
    {
        return new ForumTopic(
            id        : $topicId,
            hash      : $payload['info_hash'],
            status    : TorrentStatus::from($payload['tor_status']),
            forumId   : $forumId,
            registered: self::dateTimeFromTimestamp($payload['reg_time']),
            priority  : KeepingPriority::from($payload['keeping_priority']),
            size      : (int)$payload['tor_size_bytes'],
            poster    : $payload['topic_poster'],
            seeders   : $payload['seeders'],
            keepers   : $payload['keepers'],
            lastSeeded: self::dateTimeFromTimestamp($payload['seeder_last_seen']),
        );
    }
}
