<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\Actions;

use Generator;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Enum\KeepingPriority;
use KeepersTeam\Webtlo\Enum\TorrentStatus;
use KeepersTeam\Webtlo\External\Data\ApiError;
use KeepersTeam\Webtlo\External\Data\ForumTopic;
use KeepersTeam\Webtlo\External\Data\ForumTopicsResponse;
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

            $topicsCount = count($result['result']);

            $format = $result['format']['topic_id'];

            // Разбиваем раздачи по 500шт и лениво обрабатываем через Generator.
            $chunks = array_chunk($result['result'], 500, true);
            unset($result['result']);

            $topicGenerator = function() use ($chunks, $format, $forumId): Generator {
                foreach ($chunks as $chunk) {
                    $topics = [];
                    foreach ($chunk as $id => $data) {
                        $topics[] = self::parseStaticForumTopics(
                            forumId: $forumId,
                            topicId: (int) $id,
                            payload: array_combine($format, $data)
                        );
                    }

                    yield $topics;
                }
            };

            return new ForumTopicsResponse(
                updateTime  : self::dateTimeFromTimestamp($result['update_time']),
                totalCount  : $topicsCount,
                totalSize   : $result['total_size_bytes'],
                topicsChunks: $topicGenerator()
            );
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function parseStaticForumTopics(int $forumId, int $topicId, array $payload): ForumTopic
    {
        return new ForumTopic(
            id        : $topicId,
            hash      : $payload['info_hash'],
            status    : TorrentStatus::from($payload['tor_status']),
            name      : '',
            forumId   : $forumId,
            registered: self::dateTimeFromTimestamp($payload['reg_time']),
            priority  : KeepingPriority::from($payload['keeping_priority']),
            size      : (int) $payload['tor_size_bytes'],
            poster    : $payload['topic_poster'],
            seeders   : $payload['seeders'],
            keepers   : $payload['keepers'],
            lastSeeded: self::dateTimeFromTimestamp($payload['seeder_last_seen']),
        );
    }
}
