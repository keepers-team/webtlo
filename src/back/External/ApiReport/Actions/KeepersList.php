<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Data\Keeper;
use KeepersTeam\Webtlo\DateHelper;
use KeepersTeam\Webtlo\External\Data\ApiError;
use KeepersTeam\Webtlo\External\Data\KeepersListResponse;
use KeepersTeam\Webtlo\Helper;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

trait KeepersList
{
    /**
     * Получить список хранителей.
     */
    public function getKeepersList(): KeepersListResponse|ApiError
    {
        $dataProcessor = self::getKeepersListProcessor($this->logger);

        try {
            $response = $this->client->get(uri: 'proxy_api/v1/static/keepers_user_data');
        } catch (GuzzleException $error) {
            $code = $error->getCode();

            return ApiError::fromHttpCode($code);
        }

        return $dataProcessor($response);
    }

    private static function getKeepersListProcessor(LoggerInterface $logger): callable
    {
        return function(ResponseInterface $response) use ($logger): KeepersListResponse|ApiError {
            $result = self::decodeResponse($logger, $response);
            if ($result instanceof ApiError) {
                return $result;
            }

            return self::parseStaticKeepersList(
                Helper::convertKeysToString($result)
            );
        };
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function parseStaticKeepersList(array $result): KeepersListResponse
    {
        $format = array_flip($result['format']['user_id']);

        $keepers = [];
        foreach ($result['result'] as $keeperId => $keeper) {
            $keeperId = (int) $keeperId;

            $keepers[$keeperId] = new Keeper(
                keeperId   : $keeperId,
                keeperName : $keeper[$format['username']],
                isCandidate: (bool) $keeper[$format['is_candidate']],
            );
        }

        return new KeepersListResponse(
            updateTime: DateHelper::makeDateTime(datetime: (int) $result['update_time']),
            keepers   : $keepers
        );
    }
}
