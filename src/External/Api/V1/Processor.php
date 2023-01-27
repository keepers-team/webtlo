<?php

namespace KeepersTeam\Webtlo\External\Api\V1;

use DateTimeImmutable;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\Promise;
use JsonException;
use KeepersTeam\Webtlo\External\Validation;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

trait Processor
{
    use Validation;

    protected static function getRequestLimit(TopicSearchMode $searchMode): int
    {
        return match ($searchMode) {
            TopicSearchMode::ID => 100,
            /*
             * Hashes are longer, so to avoid HTTP 414 in legacy API
             * we're capping max identifiers per request
             */
            TopicSearchMode::HASH => 32
        };
    }

    protected static function getChunkErrorHandler(LoggerInterface $logger): callable
    {
        return function (GuzzleException $error, int $index, Promise $aggregatePromise) use (&$logger): void {
            $logger->debug('Got unexpected error when fetch chunk', [
                'index' => $index,
                'message' => $error->getMessage(),
            ]);
            $code = $error->getCode();
            $logger->error('Failed to fetch chunk', ['code' => $code]);
            $aggregatePromise->reject(ApiError::fromHttpCode($code));
        };
    }

    private static function decodeResponse(LoggerInterface &$logger, ResponseInterface $response): ApiError|array
    {
        if (self::isValidMime($logger, $response, self::$jsonMime)) {
            $rawResponse = $response->getBody()->getContents();
            try {
                $result = json_decode(json: $rawResponse, associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                $logger->error('Unable to decode JSON', ['error' => $error, 'json' => $rawResponse]);
                return ApiError::malformedJson();
            }
            if (array_key_exists('error', $result) || !array_key_exists('result', $result)) {
                return ApiError::fromLegacyError(legacyError: $result['error']);
            } else {
                return $result;
            }
        } else {
            return ApiError::invalidMime();
        }
    }

    private static function parseLegacyTopic(int $topicId, array $payload): TopicData
    {
        return new TopicData(
            id: $topicId,
            hash: $payload['info_hash'],
            forum: $payload['forum_id'],
            poster: $payload['poster_id'],
            size: $payload['size'],
            registered: (new DateTimeImmutable())->setTimestamp($payload['reg_time']),
            status: TorrentStatus::from($payload['tor_status']),
            seeders: $payload['seeders'],
            title: $payload['topic_title'],
            lastSeeded: (new DateTimeImmutable())->setTimestamp($payload['seeder_last_seen']),
            downloads: $payload['dl_count']
        );
    }

    private static function parseLegacyPeer(int|string $identifier, array $payload): PeerData
    {
        return new PeerData(
            identifier: $identifier,
            seeders: $payload[0],
            leechers: $payload[1],
            lastSeeded: (new DateTimeImmutable())->setTimestamp($payload[2]),
            keepers: $payload[3] ?? null,
        );
    }

    private static function parseLegacyForumTopics(string $key, array $value): ForumTopicsData
    {
        return new ForumTopicsData(
            id: (int)$key,
            status: TorrentStatus::from($value[0]),
            seeders: $value[1],
            registered: (new DateTimeImmutable())->setTimestamp($value[2]),
            size: $value[3],
            priority: KeepingPriority::from($value[4]),
            keepers: $value[5],
            lastSeeded: (new DateTimeImmutable())->setTimestamp($value[6]),
            hash: $value[7]
        );
    }

    private static function parseLegacyHighPriorityTopics(string $key, array $value): HighPriorityTopic
    {
        return new HighPriorityTopic(
            id: (int)$key,
            status: TorrentStatus::from($value[0]),
            seeders: $value[1],
            registered: (new DateTimeImmutable())->setTimestamp($value[2]),
            size: $value[3],
            forumId: $value[4]
        );
    }


    protected static function getTopicDataProcessor(LoggerInterface $logger, array &$knownTopics, array &$missingTopics): callable
    {
        return function (ResponseInterface $response, int $index, Promise $aggregatePromise) use (&$logger, &$knownTopics, &$missingTopics): void {
            $result = self::decodeResponse($logger, $response);
            if ($result instanceof ApiError) {
                $aggregatePromise->reject($result);
                return;
            }
            foreach ($result['result'] as $id => $payload) {
                $topicId = (int)$id;
                if (null === $payload) {
                    $missingTopics[] = $topicId;
                } else {
                    $knownTopics[] = self::parseLegacyTopic($topicId, $payload);
                }
            }
        };
    }


    protected static function getPeerDataProcessor(LoggerInterface $logger, array &$knownPeers, array &$missingTopics): callable
    {
        return function (ResponseInterface $response, int $index, Promise $aggregatePromise) use (&$logger, &$knownPeers, &$missingTopics): void {
            $result = self::decodeResponse($logger, $response);
            if ($result instanceof ApiError) {
                $aggregatePromise->reject($result);
                return;
            }

            foreach ($result['result'] as $identifier => $payload) {
                if (null === $payload) {
                    $missingTopics[] = $identifier;
                } else {
                    $knownPeers[] = self::parseLegacyPeer($identifier, $payload);
                }
            }
        };
    }

    protected static function getForumDataProcessor(LoggerInterface $logger): callable
    {
        return function (ResponseInterface $response) use (&$logger): ForumTopicsResponse|ApiError {
            $result = self::decodeResponse($logger, $response);
            if ($result instanceof ApiError) {
                return $result;
            }

            return new ForumTopicsResponse(
                updateTime: (new DateTimeImmutable())->setTimestamp($result['update_time']),
                totalSize: $result['total_size_bytes'],
                topics: array_map(
                    [self::class, 'parseLegacyForumTopics'],
                    array_keys($result['result']),
                    array_values($result['result'])
                )
            );
        };
    }

    protected static function getHighPriorityTopicProcessor(LoggerInterface $logger): callable
    {
        return function (ResponseInterface $response) use (&$logger): HighPriorityTopicsResponse|ApiError {
            $result = self::decodeResponse($logger, $response);
            if ($result instanceof ApiError) {
                return $result;
            }

            return new HighPriorityTopicsResponse(
                updateTime: (new DateTimeImmutable())->setTimestamp($result['update_time']),
                topics: array_map(
                    [self::class, 'parseLegacyHighPriorityTopics'],
                    array_keys($result['result']),
                    array_values($result['result'])
                )
            );
        };
    }
}
