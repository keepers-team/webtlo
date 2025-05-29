<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use DateTimeImmutable;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\External\ApiReport\V1\KeeperUnseededResponse;
use KeepersTeam\Webtlo\External\Data\ApiError;
use KeepersTeam\Webtlo\Helper;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

trait KeeperUnseededTopics
{
    /**
     * @param positive-int $notSeedingDays количество дней, которое раздачи не сидировались
     * @param positive-int $limitTopics    максимальное желаемое количество раздач в выдаче
     */
    public function getKeeperUnseededTopics(int $forumId, int $notSeedingDays, int $limitTopics): KeeperUnseededResponse|ApiError
    {
        $dataProcessor = self::getStaticUnseededProcessor($this->logger);

        try {
            // Вычитаем один день, чтобы компенсирование "вытекание" даты сидирования по часам.
            --$notSeedingDays;

            // Приводим значения к валидным.
            $notSeedingDays = max(0, $notSeedingDays);
            $limitTopics    = max(1, $limitTopics);

            $cutoffDate = Helper::getCurrentUtcDateTime()->modify("- $notSeedingDays days");

            // Фильтр по дате последнего сидирования.
            $dateFilter = "last_seeded_time<{$cutoffDate->format('Y-m-d')}";

            $params = [
                'subforum_id' => $forumId,
                'columns'     => 'info_hash',
                'limit'       => $limitTopics,
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
