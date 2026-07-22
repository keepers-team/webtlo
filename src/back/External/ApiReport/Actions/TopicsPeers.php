<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use Generator;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectionException;
use KeepersTeam\Webtlo\External\Data\ApiError;
use KeepersTeam\Webtlo\External\Data\TopicPeers;
use KeepersTeam\Webtlo\External\Data\TopicSearchMode;
use KeepersTeam\Webtlo\External\Data\TopicsPeersResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * @phpstan-type RequestTopics (int|string)[]
 * @phpstan-type TopicsChunks RequestTopics[]
 * @phpstan-type RequestFactory callable(): PromiseInterface
 * @phpstan-type PromiseProcessor callable(ResponseInterface, int): void
 */
trait TopicsPeers
{
    /**
     * Получить данные о пирах раздач по списку ид/хешей.
     *
     * @param RequestTopics $topics
     */
    public function getPeerStats(
        array           $topics,
        TopicSearchMode $searchMode = TopicSearchMode::HASH
    ): ApiError|TopicsPeersResponse {
        /** @var TopicPeers[] $knownTopics */
        $knownTopics = [];

        $fulfilledProcessor = $this->getPeersPayloadProcessor(...);
        $requestGenerator   = $this->getPeersRequestGenerator(searchMode: $searchMode);

        $topicChunks  = array_chunk($topics, $searchMode->paramsLimit());
        $requestCount = count($topicChunks);

        $pool = new Pool(
            client  : $this->client,
            requests: $requestGenerator($topicChunks),
            config  : [
                'concurrency' => 4,
                'fulfilled'   => $fulfilledProcessor($knownTopics, $requestCount),
                'rejected'    => self::getChunkErrorHandler(logger: $this->logger, total: $requestCount),
            ],
        );

        try {
            $pool->promise()->wait();

            $identifiers = array_map(
                static function(TopicPeers $topic) use ($searchMode): int|string {
                    return match ($searchMode) {
                        TopicSearchMode::ID   => $topic->id,
                        TopicSearchMode::HASH => $topic->hash,
                    };
                },
                $knownTopics,
            );

            $missingTopics = array_values(
                array_diff($topics, $identifiers),
            );

            return new TopicsPeersResponse(peers: $knownTopics, missingTopics: $missingTopics);
        } catch (RejectionException $rejectionException) {
            return $rejectionException->getReason();
        }
    }

    /**
     * @return callable(TopicsChunks): Generator<RequestFactory>
     */
    private function getPeersRequestGenerator(TopicSearchMode $searchMode): callable
    {
        $client = $this->client;

        $columns = [
            'info_hash',
            'seeders',
            'leechers',
            'keeper_seeders',
        ];

        // Параметры для каждого запроса в Pool.
        $options = [
            'mode'    => $searchMode->value,
            'columns' => implode(',', $columns),
        ];

        /**
         * @param TopicsChunks $topicsChunks Раздачи разделённые на группы по $requestLimit штук в одном запросе
         *
         * @return Generator<RequestFactory>
         */
        return static function(array $topicsChunks) use ($client, $options): Generator {
            foreach ($topicsChunks as $requestTopics) {
                yield static function() use ($client, $options, $requestTopics) {
                    return $client->getAsync(
                        uri    : 'releases/pvc',
                        options: [
                            'query' => [
                                ...$options,
                                'topic_ids' => implode(',', $requestTopics),
                            ],
                        ]
                    );
                };
            }
        };
    }

    /**
     * @param TopicPeers[] $knownTopics
     *
     * @return PromiseProcessor
     */
    private function getPeersPayloadProcessor(array &$knownTopics, int $requestCount): callable
    {
        $logger = $this->logger;

        return static function(ResponseInterface $response, int $index) use (
            $logger,
            &$knownTopics,
            $requestCount,
        ): void {
            $result = self::decodeResponse($logger, $response);
            if ($result instanceof ApiError) {
                $logger->debug('Failed chunk request {index}/{total}', [
                    'index'   => $index,
                    'total'   => $requestCount,
                    'reason' => (array) $result,
                ]);

                return;
            }

            $format = $result['columns'];
            foreach ($result['releases'] as $data) {
                $knownTopics[] = self::parseDynamicPeer(
                    payload: array_combine($format, $data)
                );
            }

            // Оставим для отладки.
            // $logger->debug('Done chunk request {index}/{total}', ['index' => $index, 'total' => $requestCount]);
        };
    }

    /**
     * @param array<array-key, int|string> $payload
     */
    private static function parseDynamicPeer(array $payload): TopicPeers
    {
        return new TopicPeers(
            id      : (int) $payload['topic_id'],
            hash    : (string) $payload['info_hash'],
            seeders : (int) $payload['seeders'],
            leechers: (int) $payload['leechers'],
            keepers : (int) $payload['keeper_seeders'],
        );
    }
}
