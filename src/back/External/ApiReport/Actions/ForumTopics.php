<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use DateTimeImmutable;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\ForumTopic;
use KeepersTeam\Webtlo\External\Api\V1\ForumTopicsResponse;
use KeepersTeam\Webtlo\External\Api\V1\KeepingPriority;
use KeepersTeam\Webtlo\External\Api\V1\TorrentStatus;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

trait ForumTopics
{
    /**
     * Получить список раздач подраздела.
     */
    public function getForumTopicsData(int $forumId): ForumTopicsResponse|ApiError
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

            $topics = [];
            foreach ($result['releases'] as $data) {
                $payload  = array_combine($format, $data);
                $topics[] = self::parseStaticForumTopics(forumId: $forumId, payload: $payload);
            }

            return new ForumTopicsResponse(
                updateTime: new DateTimeImmutable($result['pvc_update_time']),
                // TODO Заменить на значение из выдачи.
                totalSize : array_sum(array_column($topics, 'size')),
                topics    : $topics,
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
        return new ForumTopic(
            id        : (int) $payload['topic_id'],
            hash      : (string) $payload['info_hash'],
            status    : TorrentStatus::from((int) $payload['tor_status']),
            name      : (string) $payload['topic_title'],
            forumId   : $forumId,
            registered: new DateTimeImmutable($payload['reg_time']),
            priority  : KeepingPriority::from((int) $payload['keeping_priority']),
            size      : (int) $payload['tor_size_bytes'],
            poster    : (int) $payload['topic_poster'],
            seeders   : (int) $payload['seeders'],
            keepers   : [], // TODO УБрать хранителей.
            lastSeeded: new DateTimeImmutable($payload['seeder_last_seen']),
        );
    }
}
