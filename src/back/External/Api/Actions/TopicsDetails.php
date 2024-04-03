<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\Actions;

use Generator;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\RejectionException;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\TopicDetails;
use KeepersTeam\Webtlo\External\Api\V1\TopicSearchMode;
use KeepersTeam\Webtlo\External\Api\V1\TopicsDetailsResponse;
use KeepersTeam\Webtlo\External\Api\V1\TorrentStatus;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

trait TopicsDetails
{
    use Processor;

    /**
     * Получить сведения о раздачах по списку ид/хешей.
     *
     * @param (int|string)[]  $topics
     * @param TopicSearchMode $searchMode
     * @return ApiError|TopicsDetailsResponse
     */
    public function getTopicsDetails(
        array           $topics,
        TopicSearchMode $searchMode = TopicSearchMode::ID
    ): ApiError|TopicsDetailsResponse {
        /** @var int[] $missingTopics */
        $missingTopics = [];
        /** @var TopicDetails[] $knownTopics */
        $knownTopics = [];

        $client = $this->client;

        $chunkErrorHandler = self::getChunkErrorHandler($this->logger);
        $topicProcessor    = self::getTopicDetailsProcessor($this->logger, $knownTopics, $missingTopics);

        /**
         * @param array[][] $optionsChunks
         * @return Generator
         */
        $requests = function(array $optionsChunks) use (&$client) {
            foreach ($optionsChunks as $chunk) {
                yield function(array $options) use (&$client, &$chunk) {
                    return $client->getAsync(
                        uri    : 'get_tor_topic_data',
                        options: ['query' => ['val' => implode(',', $chunk), ...$options]]
                    );
                };
            }
        };

        $requestLimit  = self::getRequestLimit($searchMode);
        $requestConfig = [
            'concurrency' => self::$concurrency,
            'options'     => ['by' => $searchMode->value, ...$this->defaultParams],
            'fulfilled'   => $topicProcessor,
            'rejected'    => $chunkErrorHandler,
        ];

        $pool = new Pool(
            client  : $client,
            requests: $requests(array_chunk($topics, $requestLimit)),
            config  : $requestConfig,
        );

        try {
            $pool->promise()->wait();

            return new TopicsDetailsResponse(topics: $knownTopics, missingTopics: $missingTopics);
        } catch (RejectionException $rejectionException) {
            return $rejectionException->getReason();
        }
    }

    /**
     * @param LoggerInterface $logger
     * @param TopicDetails[]  $knownTopics
     * @param int[]           $missingTopics
     * @return callable
     */
    private static function getTopicDetailsProcessor(
        LoggerInterface $logger,
        array           &$knownTopics,
        array           &$missingTopics
    ): callable {
        return function(ResponseInterface $response, int $index, Promise $aggregatePromise) use (
            &$logger,
            &$knownTopics,
            &$missingTopics
        ): void {
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
                    $knownTopics[] = self::parseDynamicTopicDetails($topicId, $payload);
                }
            }
        };
    }

    /**
     * @param int                       $topicId
     * @param array<string, int|string> $payload
     * @return TopicDetails
     */
    private static function parseDynamicTopicDetails(int $topicId, array $payload): TopicDetails
    {
        return new TopicDetails(
            id        : $topicId,
            hash      : $payload['info_hash'],
            forumId   : $payload['forum_id'],
            poster    : $payload['poster_id'],
            size      : (int)$payload['size'],
            registered: self::dateTimeFromTimestamp($payload['reg_time']),
            status    : TorrentStatus::from($payload['tor_status']),
            seeders   : $payload['seeders'],
            title     : $payload['topic_title'],
            lastSeeded: self::dateTimeFromTimestamp($payload['seeder_last_seen']),
            downloads : $payload['dl_count']
        );
    }
}
