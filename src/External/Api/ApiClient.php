<?php

namespace KeepersTeam\Webtlo\External\Api;

use Generator;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\RejectionException;
use KeepersTeam\Webtlo\Config\Defaults;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\Config\Timeout;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\Processor;
use KeepersTeam\Webtlo\External\Api\V1\TopicData;
use KeepersTeam\Webtlo\External\Api\V1\TopicSearchMode;
use KeepersTeam\Webtlo\External\Api\V1\TopicsResponse;
use KeepersTeam\Webtlo\External\WebClient;
use Psr\Log\LoggerInterface;

class ApiClient extends WebClient
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
