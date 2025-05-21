<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use DateTimeImmutable;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\External\ApiReport\V1\KeeperUnseededResponse;
use KeepersTeam\Webtlo\External\Data\ApiError;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

trait KeeperUnseededTopics
{
    public function getKeeperUnseededTopics(int $forumId, int $notSeedingDays): KeeperUnseededResponse|ApiError
    {
        $dataProcessor = self::getStaticUnseededProcessor($this->logger);

        try {
            // Новая возможность в API - фильтр по дате последнего сидирования.
            $dateFilter = "last_seeded_time<{$notSeedingDays}d";

            $params = [
                'subforum_id' => $forumId,
                'columns'     => 'info_hash,last_seeded_time',
                'conditions'  => $dateFilter,
            ];

            $response = $this->client->get(uri: "keeper/{$this->auth->userId}/reports", options: ['query' => $params]);
        } catch (GuzzleException $error) {
            $code = $error->getCode();

            return ApiError::fromHttpCode(code: $code);
        }

        return $dataProcessor($response, $forumId);
    }

    private static function getStaticUnseededProcessor(LoggerInterface $logger): callable
    {
        return function(ResponseInterface $response, int $forumId) use (&$logger): KeeperUnseededResponse|ApiError {
            $result = self::decodeResponse($logger, $response);
            if ($result instanceof ApiError) {
                return $result;
            }

            foreach ($result as $subforum) {
                if ((int) $subforum['subforum_id'] === $forumId) {
                    return new KeeperUnseededResponse(
                        subForumId: $subforum['subforum_id'],
                        totalCount: $subforum['total_count'],
                        cacheTime : new DateTimeImmutable($subforum['cache_time']),
                        columns   : $subforum['columns'],
                        releases  : $subforum['kept_releases'],
                    );
                }
            }

            return ApiError::fromLegacyError(['text' => "SubForumId $forumId not found in API response"]);
        };
    }
}
