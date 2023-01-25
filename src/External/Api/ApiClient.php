<?php

namespace KeepersTeam\Webtlo\External\Api;

use DateTimeImmutable;
use Generator;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\RejectionException;
use JsonException;
use KeepersTeam\Webtlo\Config\Defaults;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\Config\Timeout;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\TopicData;
use KeepersTeam\Webtlo\External\Api\V1\TopicSearchMode;
use KeepersTeam\Webtlo\External\Api\V1\TopicsResponse;
use KeepersTeam\Webtlo\External\Api\V1\TorrentStatus;
use KeepersTeam\Webtlo\External\WebClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class ApiClient extends WebClient
{
    private static string $apiVersion = 'v1';
    private static int $concurrency = 4;
    private readonly array $defaultParams;

    public function __construct(
        string $apiKey,
        LoggerInterface $logger,
        ?Proxy $proxy = null,
        string $apiURL = Defaults::apiUrl,
        Timeout $timeout = new Timeout(),
    ) {
        parent::__construct(
            logger: $logger,
            baseURL: sprintf("%s/%s", $apiURL, self::$apiVersion),
            proxy: $proxy,
            timeout: $timeout
        );
        $this->defaultParams = [
            'api_key' => $apiKey,
        ];
    }

    private static function getRequestLimit(TopicSearchMode $searchMode): int
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

    private static function getChunkErrorHandler(LoggerInterface $logger): callable
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

    private static function isLegacyError(array $data): bool
    {
        return array_key_exists('error', $data) || !array_key_exists('result', $data);
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

    private static function getTopicDataProcessor(LoggerInterface $logger, array &$knownTopics, array &$missingTopics): callable
    {
        return function (ResponseInterface $response, int $index, Promise $aggregatePromise) use (&$logger, &$knownTopics, &$missingTopics): void {
            if (self::isValidMime($logger, $response, self::jsonMime)) {
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

    /**
     * @param string[] $topics
     */
    public function getTorrentTopicData(array $topics, TopicSearchMode $searchMode): ApiError|TopicsResponse
    {
        /** @var int[] $missingTopics */
        $missingTopics = [];
        /** @var TopicData[] $knownTopics */
        $knownTopics = [];
        $client = $this->client;
        $chunkErrorHandler = $this->getChunkErrorHandler($this->logger);
        $topicProcessor = $this->getTopicDataProcessor($this->logger, $knownTopics, $missingTopics);
        /**
         * @param array[][] $optionsChunks
         * @return Generator
         */
        $requests = function (array $optionsChunks) use (&$client) {
            foreach ($optionsChunks as $chunk) {
                yield function (array $options) use (&$client, &$chunk) {
                    return $client->getAsync(
                        uri: 'get_tor_topic_data',
                        options: ['query' => ['val' => implode(',', $chunk), ...$options]]
                    );
                };
            }
        };

        $requestLimit = self::getRequestLimit($searchMode);
        $pool = new Pool(
            client: $client,
            requests: $requests(array_chunk($topics, $requestLimit)),
            config: [
                'concurrency' => self::$concurrency,
                'options' => ['by' => $searchMode->value, ...$this->defaultParams],
                'fulfilled' => $topicProcessor,
                'rejected' => $chunkErrorHandler,
            ]
        );

        try {
            $pool->promise()->wait(unwrap: true);
            return new TopicsResponse(topics: $knownTopics, missingTopics: $missingTopics);
        } catch (RejectionException $rejectionException) {
            return $rejectionException->getReason();
        }
    }
}
