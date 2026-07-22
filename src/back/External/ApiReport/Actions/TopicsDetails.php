<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectionException;
use KeepersTeam\Webtlo\Enum\KeepingPriority;
use KeepersTeam\Webtlo\Enum\TorrentStatus;
use KeepersTeam\Webtlo\External\Api\V1\TopicDetails;
use KeepersTeam\Webtlo\External\Api\V1\TopicsDetailsResponse;
use KeepersTeam\Webtlo\External\Api\V1\TopicSearchMode;
use KeepersTeam\Webtlo\External\Data\ApiError;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * @phpstan-type RequestTopics (int|string)[]
 * @phpstan-type TopicsChunks RequestTopics[]
 * @phpstan-type RequestFactory callable(): PromiseInterface
 * @phpstan-type PromiseProcessor callable(ResponseInterface, int): void
 */
trait TopicsDetails
{
    /**
     * Получить сведения о раздачах по списку ид/хешей.
     *
     * @param RequestTopics $topics
     */
    public function getTopicsDetails(
        array           $topics,
        TopicSearchMode $searchMode = TopicSearchMode::ID,
    ): ApiError|TopicsDetailsResponse {
        // Ищем раздачи в актуальном API.
        $actualTopics = $this->getTopicsDetailsConcurrently(
            topics            : $topics,
            searchMode        : $searchMode,
            requestGenerator  : $this->getActualTopicsGenerator($searchMode),
            fulfilledProcessor: $this->getActualTopicProcessor(...),
        );

        if ($actualTopics instanceof ApiError) {
            return $actualTopics;
        }

        // Вычисляем те, которые найти не удалось.
        $missingTopics = self::findMissingTopics(
            requestTopics: $topics,
            knownTopics  : $actualTopics,
            searchMode   : $searchMode
        );

        $oldTopics = [];

        // Ищем не найденное в "прошлых релизах".
        if ($missingTopics !== []) {
            $oldTopics = $this->getTopicsDetailsConcurrently(
                topics            : $missingTopics,
                searchMode        : $searchMode,
                requestGenerator  : $this->getOldTopicsGenerator(),
                fulfilledProcessor: $this->getOldTopicsProcessor(...),
            );

            // Ошибка, в данном случае, нам не интересна.
            if ($oldTopics instanceof ApiError) {
                $oldTopics = [];
            }

            // Вычисляем те, которые снова найти не удалось.
            if ($oldTopics !== []) {
                $missingTopics = self::findMissingTopics(
                    requestTopics: $missingTopics,
                    knownTopics  : $oldTopics,
                    searchMode   : $searchMode
                );
            }
        }

        return new TopicsDetailsResponse(
            actualTopics : $actualTopics,
            oldTopics    : $oldTopics,
            missingTopics: $missingTopics,
        );
    }

    /**
     * @param RequestTopics                                     $topics
     * @param callable(TopicsChunks): Generator<RequestFactory> $requestGenerator
     * @param callable(TopicDetails[], int): PromiseProcessor   $fulfilledProcessor
     *
     * @return ApiError|TopicDetails[]
     */
    private function getTopicsDetailsConcurrently(
        array           $topics,
        TopicSearchMode $searchMode,
        callable        $requestGenerator,
        callable        $fulfilledProcessor,
    ): ApiError|array {
        $knownTopics = [];

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
        } catch (RejectionException $rejectionException) {
            return $rejectionException->getReason();
        }

        return $knownTopics;
    }

    /**
     * @return callable(TopicsChunks): Generator<RequestFactory>
     */
    private function getActualTopicsGenerator(TopicSearchMode $searchMode): callable
    {
        $client = $this->client;

        $columns = [
            'info_hash',
            'tor_status',
            'topic_title',
            'reg_time',
            'keeping_priority',
            'tor_size_bytes',
            'topic_poster',
            'seeders',
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
     * @return callable(TopicsChunks): Generator<RequestFactory>
     */
    private function getOldTopicsGenerator(): callable
    {
        $client = $this->client;

        /**
         * @param TopicsChunks $topicsChunks Раздачи разделённые на группы по $requestLimit штук в одном запросе
         *
         * @return Generator<RequestFactory>
         */
        return static function(array $topicsChunks) use ($client): Generator {
            foreach ($topicsChunks as $topics) {
                yield static function() use ($client, $topics) {
                    return $client->getAsync(
                        uri: 'old_releases_versions/' . implode(',', $topics),
                    );
                };
            }
        };
    }

    /**
     * @param TopicDetails[] $knownTopics
     *
     * @return PromiseProcessor
     */
    private function getActualTopicProcessor(array &$knownTopics, int $requestCount): callable
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
                    'index'  => $index,
                    'total'  => $requestCount,
                    'reason' => (array) $result,
                ]);

                return;
            }

            $format = $result['columns'];

            foreach ($result['releases'] as $data) {
                $knownTopics[] = self::parseDynamicTopicDetails(
                    payload: array_combine($format, $data)
                );
            }

            // Оставим для отладки.
            // $logger->debug('Done chunk request {index}/{total}', ['index' => $index, 'total' => $requestCount]);
        };
    }

    /**
     * @param TopicDetails[] $knownTopics
     *
     * @return PromiseProcessor
     */
    private function getOldTopicsProcessor(array &$knownTopics, int $requestCount): callable
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
                    'index'  => $index,
                    'total'  => $requestCount,
                    'reason' => (array) $result,
                ]);

                return;
            }

            $format = $result['columns'];

            foreach ($result['releases'] as $data) {
                // Проверим, что последняя версия релиза есть.
                $lastVersion = $data['versions'][0] ?? null;
                if (!is_array($lastVersion)) {
                    continue;
                }

                $knownTopics[] = self::parseDynamicTopicDetails(
                    payload: array_combine($format, $lastVersion)
                );
            }

            // Оставим для отладки.
            // $logger->debug('Done chunk request {index}/{total}', ['index' => $index, 'total' => $requestCount]);
        };
    }

    /**
     * @param array<array-key, int|string> $payload
     */
    private static function parseDynamicTopicDetails(array $payload): TopicDetails
    {
        return new TopicDetails(
            id        : (int) $payload['topic_id'],
            hash      : (string) $payload['info_hash'],
            forumId   : (int) $payload['subforum_id'],
            poster    : (int) $payload['topic_poster'],
            size      : (int) $payload['tor_size_bytes'],
            registered: self::parseDateTime((string) $payload['reg_time']),
            status    : TorrentStatus::from((int) $payload['tor_status']),
            priority  : KeepingPriority::from((int) $payload['keeping_priority']),
            seeders   : (int) $payload['seeders'],
            title     : (string) $payload['topic_title'],
        );
    }

    /**
     * Вычислить идентификаторы раздач, которые не удалось найти.
     *
     * @param RequestTopics  $requestTopics
     * @param TopicDetails[] $knownTopics
     *
     * @return RequestTopics
     */
    private static function findMissingTopics(
        array           $requestTopics,
        array           $knownTopics,
        TopicSearchMode $searchMode,
    ): array {
        $identifiers = array_map(
            static function(TopicDetails $topic) use ($searchMode): int|string {
                return match ($searchMode) {
                    TopicSearchMode::ID   => $topic->id,
                    TopicSearchMode::HASH => $topic->hash,
                };
            },
            $knownTopics,
        );

        return array_values(
            array_diff($requestTopics, $identifiers),
        );
    }

    private static function parseDateTime(string $time): DateTimeImmutable
    {
        // Пробуем обработать полученною дату.
        try {
            if (!empty($time)) {
                return new DateTimeImmutable($time, new DateTimeZone('UTC'));
            }
        } catch (Throwable) {
        }

        // Если не срослось - используем unix ноль.
        return (new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'))->setTimestamp(0);
    }
}
