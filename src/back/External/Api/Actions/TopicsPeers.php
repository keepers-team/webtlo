<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\Actions;

use Generator;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\RejectionException;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\TopicPeers;
use KeepersTeam\Webtlo\External\Api\V1\TopicsPeersResponse;
use KeepersTeam\Webtlo\External\Api\V1\TopicSearchMode;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

trait TopicsPeers
{
    use Processor;

    /**
     * Получить данные о пирах раздач по списку ид/хешей.
     *
     * @param (int|string)[]  $topics
     * @param TopicSearchMode $searchMode
     * @return ApiError|TopicsPeersResponse
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
         * @param array[][] $optionsChunks
         * @return Generator
         */
        $requests = function(array $optionsChunks) use (&$client) {
            foreach ($optionsChunks as $chunk) {
                yield function(array $options) use (&$client, &$chunk) {
                    return $client->getAsync(
                        uri    : 'get_peer_stats',
                        options: ['query' => ['val' => implode(',', $chunk), ...$options]]
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
     * @param LoggerInterface $logger
     * @param TopicPeers[]    $knownPeers
     * @param (int|string)[]  $missingTopics
     * @return callable
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
                if (null === $payload) {
                    $missingTopics[] = $identifier;
                } else {
                    $knownPeers[] = self::parseDynamicPeer($identifier, $payload);
                }
            }
        };
    }

    /**
     * @param int|string        $identifier
     * @param array<int, mixed> $payload
     * @return TopicPeers
     */
    private static function parseDynamicPeer(int|string $identifier, array $payload): TopicPeers
    {
        return new TopicPeers(
            identifier: $identifier,
            seeders   : $payload[0],
            leechers  : $payload[1],
            lastSeeded: self::dateTimeFromTimestamp($payload[2]),
            keepers   : $payload[3] ?? null,
        );
    }
}
