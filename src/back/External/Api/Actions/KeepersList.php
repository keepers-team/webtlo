<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\Actions;

use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\KeeperData;
use KeepersTeam\Webtlo\External\Api\V1\KeepersResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

trait KeepersList
{
    use Processor;

    /** Получить список хранителей. */
    public function getKeepersList(): KeepersResponse|ApiError
    {
        $dataProcessor = self::getKeepersProcessor($this->logger);
        try {
            $response = $this->client->get(uri: 'static/keepers_user_data');
        } catch (GuzzleException $error) {
            $code = $error->getCode();

            return ApiError::fromHttpCode($code);
        }

        return $dataProcessor($response);
    }

    private static function getKeepersProcessor(LoggerInterface $logger): callable
    {
        return function(ResponseInterface $response) use (&$logger): KeepersResponse|ApiError {
            $result = self::decodeResponse($logger, $response);
            if ($result instanceof ApiError) {
                return $result;
            }

            return self::parseStaticKeepersList($result);
        };
    }

    /**
     * @param array<string, mixed> $result
     * @return KeepersResponse
     */
    private static function parseStaticKeepersList(array $result): KeepersResponse
    {
        $format = array_flip($result['format']['user_id']);

        $keepers = [];
        foreach ($result['result'] as $keeperId => $keeper) {
            $keepers[] = new KeeperData(
                keeperId   : $keeperId,
                keeperName : $keeper[$format['username']],
                isCandidate: (bool)$keeper[$format['is_candidate']],
            );
        }

        return new KeepersResponse(
            updateTime: self::dateTimeFromTimestamp($result['update_time']),
            keepers   : $keepers
        );
    }
}
