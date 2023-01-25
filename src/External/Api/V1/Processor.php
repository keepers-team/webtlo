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

    protected static function isLegacyError(array $data): bool
    {
        return array_key_exists('error', $data) || !array_key_exists('result', $data);
    }

    protected static function parseLegacyTopic(int $topicId, array $payload): TopicData
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

    protected static function getTopicDataProcessor(LoggerInterface $logger, array &$knownTopics, array &$missingTopics): callable
    {
        return function (ResponseInterface $response, int $index, Promise $aggregatePromise) use (&$logger, &$knownTopics, &$missingTopics): void {
            if (self::isValidMime($logger, $response, self::$jsonMime)) {
                $rawResponse = $response->getBody()->getContents();
                try {
                    $result = json_decode(json: $rawResponse, associative: true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException $error) {
                    $logger->error('Unable to decode JSON', ['error' => $error, 'json' => $rawResponse]);
                    $aggregatePromise->reject(ApiError::malformedJson());
                    return;
                }
                if (self::isLegacyError($result)) {
                    $aggregatePromise->reject(ApiError::fromLegacyError(legacyError: $result['error']));
                } else {
                    foreach ($result['result'] as $id => $payload) {
                        $topicId = (int)$id;
                        if (null === $payload) {
                            $missingTopics[] = $topicId;
                        } else {
                            $knownTopics[] = self::parseLegacyTopic($topicId, $payload);
                        }
                    }
                }
            } else {
                $aggregatePromise->reject(ApiError::invalidMime());
            }
        };
    }
}
