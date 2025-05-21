<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use DateTimeImmutable;
use Exception;
use Generator;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Enum\KeepingPriority;
use KeepersTeam\Webtlo\Enum\TorrentStatus;
use KeepersTeam\Webtlo\External\Data\ApiError;
use KeepersTeam\Webtlo\External\Data\AverageSeeds;
use KeepersTeam\Webtlo\External\Data\ForumTopic;
use KeepersTeam\Webtlo\External\Data\ForumTopicsResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

trait ForumTopics
{
    /**
     * Получить список раздач подраздела.
     */
    public function getForumTopicsData(int $forumId, bool $loadAverageSeeds = false): ForumTopicsResponse|ApiError
    {
        $dataProcessor = self::getForumTopicsProcessor($this->logger, $forumId);

        $columns = [
            'info_hash',
            'tor_status',
            'topic_title',
            'reg_time',
            'keeping_priority',
            'tor_size_bytes',
            'topic_poster',
            'seeders',
            'seeder_last_seen',
        ];

        // Если нужна история сидов, добавляем искомые поля.
        if ($loadAverageSeeds) {
            $columns = [...$columns, 'average_seeds_sum', 'average_seeds_count'];
        }

        try {
            $params = [
                'columns' => implode(',', $columns),
            ];

            $response = $this->client->get(uri: "subforum/$forumId/pvc", options: ['query' => $params]);
        } catch (GuzzleException $error) {
            $code = $error->getCode();

            return ApiError::fromHttpCode(code: $code);
        }

        return $dataProcessor($response);
    }

    private static function getForumTopicsProcessor(LoggerInterface $logger, int $forumId): callable
    {
        return function(ResponseInterface $response) use (&$logger, $forumId): ForumTopicsResponse|ApiError {
            $result = self::decodeResponse($logger, $response);
            if ($result instanceof ApiError) {
                return $result;
            }

            $format = $result['columns'];

            // TODO Заменить на значение из выдачи.
            $totalSize = array_sum(
                array_column(
                    $result['releases'],
                    array_flip($format)['tor_size_bytes']
                )
            );

            // Разбиваем раздачи по 500шт и лениво обрабатываем через Generator.
            $chunks = array_chunk($result['releases'], 500);
            unset($result['releases']);

            $topicGenerator = function() use ($chunks, $format, $forumId): Generator {
                foreach ($chunks as $chunk) {
                    $topics = [];
                    foreach ($chunk as $data) {
                        $topics[] = self::parseStaticForumTopics(
                            forumId: $forumId,
                            payload: array_combine($format, $data)
                        );
                    }

                    yield $topics;
                }
            };

            return new ForumTopicsResponse(
                updateTime  : new DateTimeImmutable($result['pvc_update_time'] ?? $result['cache_time']),
                totalCount  : $result['total_count'],
                totalSize   : $totalSize,
                topicsChunks: $topicGenerator(),
            );
        };
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws Exception
     */
    private static function parseStaticForumTopics(int $forumId, array $payload): ForumTopic
    {
        $averageSeeds = null;
        if (!empty($payload['average_seeds_sum'])) {
            $sum   = array_map('intval', $payload['average_seeds_sum']);
            $count = array_map('intval', $payload['average_seeds_count']);

            // Данные о средних сидах
            $averageSeeds = new AverageSeeds(
                sum         : $sum[0],
                count       : $count[0],
                sumHistory  : array_slice($sum, 1, 30),
                countHistory: array_slice($count, 1, 30)
            );
        }

        return new ForumTopic(
            id          : (int) $payload['topic_id'],
            hash        : (string) $payload['info_hash'],
            status      : TorrentStatus::from((int) $payload['tor_status']),
            name        : (string) $payload['topic_title'],
            forumId     : $forumId,
            registered  : new DateTimeImmutable($payload['reg_time']),
            priority    : KeepingPriority::from((int) $payload['keeping_priority']),
            size        : (int) $payload['tor_size_bytes'],
            poster      : (int) $payload['topic_poster'],
            seeders     : (int) $payload['seeders'],
            keepers     : [], // TODO УБрать хранителей.
            lastSeeded  : new DateTimeImmutable($payload['seeder_last_seen']),
            averageSeeds: $averageSeeds,
        );
    }
}
