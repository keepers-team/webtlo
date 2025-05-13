<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\Actions;

use Generator;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\RejectionException;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\TopicDetails;
use KeepersTeam\Webtlo\External\Api\V1\TopicsDetailsResponse;
use KeepersTeam\Webtlo\External\Api\V1\TopicSearchMode;
use KeepersTeam\Webtlo\External\Api\V1\TorrentStatus;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

trait TopicsDetails
{
    use Processor;

    /**
     * Получить сведения о раздачах по списку ид/хешей.
     *
     * @param (int|string)[] $topics
     */
    public function getTopicsDetails(
        array           $topics,
        TopicSearchMode $searchMode = TopicSearchMode::ID
    ): ApiError|TopicsDetailsResponse {
        /** @var TopicDetails[] $knownTopics */
        $knownTopics = [];

        $client = $this->client;

        $chunkErrorHandler = self::getChunkErrorHandler($this->logger);
        $topicProcessor    = self::getTopicDetailsProcessor($this->logger, $knownTopics);

        /**
         * @param array[][] $topicsChunks Раздачи разделённые на группы по $requestLimit штук в одном запросе
         *
         * @return Generator
         */
        $requests = static function(array $topicsChunks) use (&$client) {
            foreach ($topicsChunks as $requestTopics) {
                yield static function(array $options) use (&$client, &$requestTopics) {
                    return $client->getAsync(
                        uri    : 'get_tor_topic_data',
                        options: ['query' => ['val' => implode(',', $requestTopics), ...$options]]
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

            $identifiers = array_map(static function($el) use ($searchMode) {
                return match ($searchMode) {
                    TopicSearchMode::ID   => $el->id,
                    TopicSearchMode::HASH => $el->hash,
                };
            }, $knownTopics);

            /** @var (int|string)[] $missingTopics */
            $missingTopics = array_values(array_diff($topics, $identifiers));

            return new TopicsDetailsResponse(topics: $knownTopics, missingTopics: $missingTopics);
        } catch (RejectionException $rejectionException) {
            return $rejectionException->getReason();
        }
    }

    /**
     * @param TopicDetails[] $knownTopics
     */
    private static function getTopicDetailsProcessor(
        LoggerInterface $logger,
        array           &$knownTopics,
    ): callable {
        return static function(ResponseInterface $response, int $index) use (
            &$logger,
            &$knownTopics,
        ): void {
            $result = self::decodeResponse($logger, $response);
            if ($result instanceof ApiError) {
                $logger->debug('Failed chunk request', ['index' => $index, 'reason' => (array) $result]);

                return;
            }

            foreach ($result['result'] as $id => $payload) {
                $topicId = (int) $id;
                if ($payload !== null) {
                    $knownTopics[] = self::parseDynamicTopicDetails($topicId, $payload);
                }
            }
        };
    }

    /**
     * @param array<string, int|string> $payload
     */
    private static function parseDynamicTopicDetails(int $topicId, array $payload): TopicDetails
    {
        return new TopicDetails(
            id        : $topicId,
            hash      : (string) $payload['info_hash'],
            forumId   : (int) $payload['forum_id'],
            poster    : (int) $payload['poster_id'],
            size      : (int) $payload['size'],
            registered: self::dateTimeFromTimestamp((int) $payload['reg_time']),
            status    : TorrentStatus::from($payload['tor_status']),
            seeders   : (int) $payload['seeders'],
            title     : (string) $payload['topic_title'],
            lastSeeded: self::dateTimeFromTimestamp((int) $payload['seeder_last_seen']),
            downloads : (int) $payload['dl_count']
        );
    }
}
