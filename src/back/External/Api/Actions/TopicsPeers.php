<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\Actions;

use Generator;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\RejectionException;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\TopicSearchMode;
use KeepersTeam\Webtlo\External\Api\V1\TopicsPeersResponse;
use KeepersTeam\Webtlo\External\Data\TopicPeers;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

trait TopicsPeers
{
    use Processor;

    /**
     * Получить данные о пирах раздач по списку ид/хешей.
     *
     * @param (int|string)[] $topics
     */
    public function getPeerStats(
        array           $topics,
        TopicSearchMode $searchMode = TopicSearchMode::HASH
    ): ApiError|TopicsPeersResponse {
        /** @var int[] $missingTopics */
        $missingTopics = [];
        /** @var TopicPeers[] $knownPeers */
        $knownPeers = [];

        $client = $this->client;

        $chunkErrorHandler = self::getChunkErrorHandler($this->logger);
        $peerProcessor     = self::getDynamicPeerProcessor($this->logger, $knownPeers, $missingTopics);

        /**
         * @param array[][] $topicsChunks
         *
         * @return Generator
         */
        $requests = function(array $topicsChunks) use (&$client) {
            // Увеличим количество попыток, т.к. много запросов.
            $retry = [
                'max_retry_attempts'       => 6,
                'default_retry_multiplier' => 7.5,
            ];

            foreach ($topicsChunks as $chunk) {
                yield function(array $options) use (&$client, &$chunk, $retry) {
                    return $client->getAsync(
                        uri    : 'get_peer_stats',
                        options: ['query' => ['val' => implode(',', $chunk), ...$options], ...$retry]
                    );
                };
            }
        };

        $requestLimit  = self::getRequestLimit($searchMode);
        $requestConfig = [
            'concurrency' => self::$concurrency,
            'options'     => ['by' => $searchMode->value, ...$this->defaultParams],
            'fulfilled'   => $peerProcessor,
            'rejected'    => $chunkErrorHandler,
        ];

        $pool = new Pool(
            client  : $client,
            requests: $requests(array_chunk($topics, $requestLimit)),
            config  : $requestConfig,
        );

        try {
            $pool->promise()->wait();

            return new TopicsPeersResponse(peers: $knownPeers, missingTopics: $missingTopics);
        } catch (RejectionException $rejectionException) {
            return $rejectionException->getReason();
        }
    }

    /**
     * @param TopicPeers[]   $knownPeers
     * @param (int|string)[] $missingTopics
     */
    private static function getDynamicPeerProcessor(
        LoggerInterface $logger,
        array           &$knownPeers,
        array           &$missingTopics
    ): callable {
        return function(ResponseInterface $response, int $index, Promise $aggregatePromise) use (
            &$logger,
            &$knownPeers,
            &$missingTopics
        ): void {
            $result = self::decodeResponse($logger, $response);
            if ($result instanceof ApiError) {
                $aggregatePromise->reject($result);

                return;
            }

            foreach ($result['result'] as $identifier => $payload) {
                if ($payload === null) {
                    $missingTopics[] = $identifier;
                } else {
                    $knownPeers[] = self::parseDynamicPeer($identifier, $payload);
                }
            }
        };
    }

    /**
     * @param array<int, mixed> $payload
     */
    private static function parseDynamicPeer(int|string $identifier, array $payload): TopicPeers
    {
        return new TopicPeers(
            id      : is_int($identifier) ? $identifier : -1,
            hash    : is_string($identifier) ? $identifier : 'unknown',
            seeders : (int) $payload[0],
            leechers: (int) $payload[1],
            keepers : count($payload[3] ?? []),
        );
    }
}
