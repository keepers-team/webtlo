<?php

namespace KeepersTeam\Webtlo\External\Api;

use Generator;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise;
use GuzzleHttp\Promise\RejectionException;
use KeepersTeam\Webtlo\Config\Defaults;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\Config\Timeout;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\ForumTopicsResponse;
use KeepersTeam\Webtlo\External\Api\V1\ForumsResponse;
use KeepersTeam\Webtlo\External\Api\V1\HighPriorityTopicsResponse;
use KeepersTeam\Webtlo\External\Api\V1\KeepersResponse;
use KeepersTeam\Webtlo\External\Api\V1\PeerData;
use KeepersTeam\Webtlo\External\Api\V1\PeerResponse;
use KeepersTeam\Webtlo\External\Api\V1\Processor;
use KeepersTeam\Webtlo\External\Api\V1\TopicData;
use KeepersTeam\Webtlo\External\Api\V1\TopicSearchMode;
use KeepersTeam\Webtlo\External\Api\V1\TopicsResponse;
use KeepersTeam\Webtlo\External\WebClient;
use Psr\Log\LoggerInterface;
use Throwable;

final class ApiClient extends WebClient
{
    use Processor;

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
            baseURL: sprintf("%s/%s/", $apiURL, self::$apiVersion),
            proxy: $proxy,
            timeout: $timeout
        );
        $this->defaultParams = [
            'api_key' => $apiKey,
        ];
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
        $chunkErrorHandler = self::getChunkErrorHandler($this->logger);
        $topicProcessor = self::getTopicDataProcessor($this->logger, $knownTopics, $missingTopics);
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

    public function getPeerStats(array $topics, TopicSearchMode $searchMode): ApiError|PeerResponse
    {
        /** @var int[] $missingTopics */
        $missingTopics = [];
        /** @var PeerData[] $knownPeers */
        $knownPeers = [];
        $client = $this->client;
        $chunkErrorHandler = self::getChunkErrorHandler($this->logger);
        $peerProcessor = self::getPeerDataProcessor($this->logger, $knownPeers, $missingTopics);

        /**
         * @param array[][] $optionsChunks
         * @return Generator
         */
        $requests = function (array $optionsChunks) use (&$client) {
            foreach ($optionsChunks as $chunk) {
                yield function (array $options) use (&$client, &$chunk) {
                    return $client->getAsync(
                        uri: 'get_peer_stats',
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
                'fulfilled' => $peerProcessor,
                'rejected' => $chunkErrorHandler,
            ]
        );

        try {
            $pool->promise()->wait(unwrap: true);
            return new PeerResponse(peers: $knownPeers, missingTopics: $missingTopics);
        } catch (RejectionException $rejectionException) {
            return $rejectionException->getReason();
        }
    }

    public function getForumTopicsData(int $forumId): ForumTopicsResponse|ApiError
    {
        $dataProcessor = self::getForumDataProcessor($this->logger);
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

    public function getForums(): ForumsResponse|ApiError
    {
        $dataProcessor = self::getForumProcessor($this->logger);
        $requests = [
            $this->client->getAsync('static/cat_forum_tree'),
            $this->client->getAsync('static/forum_size'),
        ];
        try {
            [$treeResponse, $sizeResponse] = Promise\Utils::unwrap($requests);
        } catch (GuzzleException $error) {
            $code = $error->getCode();
            return ApiError::fromHttpCode($code);
        } catch (Throwable) {
            // Just in case
            return ApiError::fromLegacyError(legacyError: null);
        }

        return $dataProcessor($treeResponse, $sizeResponse);
    }

    public function getKeepersUserData(): KeepersResponse|ApiError
    {
        $dataProcessor = self::getKeepersProcessor($this->logger);
        try {
            $response = $this->client->get(uri: 'static/keepers_user_data');
        } catch (GuzzleException $error) {
            $code = $error->getCode();
            return ApiError::fromHttpCode($code);
        }

        return $dataProcessor($response);
    }
}
