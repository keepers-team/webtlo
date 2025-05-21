<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use DateTimeImmutable;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\External\Data\ApiError;
use KeepersTeam\Webtlo\External\Data\TopicsPeers;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

trait ForumTopicsPeers
{
    public function getForumTopicsPeers(int $forumId): TopicsPeers|ApiError
    {
        $dataProcessor = self::getStaticPeersProcessor($this->logger);

        try {
            $params = [
                'columns' => 'info_hash,seeders,leechers,keeper_seeders',
            ];

            $response = $this->client->get(uri: "subforum/$forumId/pvc", options: ['query' => $params]);
        } catch (GuzzleException $error) {
            $code = $error->getCode();

            return ApiError::fromHttpCode(code: $code);
        }

        return $dataProcessor($response);
    }

    private static function getStaticPeersProcessor(LoggerInterface $logger): callable
    {
        return function(ResponseInterface $response) use (&$logger): TopicsPeers|ApiError {
            $result = self::decodeResponse($logger, $response);
            if ($result instanceof ApiError) {
                return $result;
            }

            return new TopicsPeers(
                subForumId: $result['subforum_id'],
                totalCount: $result['total_count'],
                cacheTime : new DateTimeImmutable($result['cache_time']),
                columns   : $result['columns'],
                releases  : $result['releases'],
            );
        };
    }
}
