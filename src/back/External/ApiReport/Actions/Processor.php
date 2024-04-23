<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use JsonException;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Shared\Validation;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

trait Processor
{
    use Validation;

    /**
     * @param LoggerInterface   $logger
     * @param ResponseInterface $response
     * @return array<int|string, mixed>|ApiError
     */
    protected static function decodeResponse(LoggerInterface $logger, ResponseInterface $response): array|ApiError
    {
        if (!self::isValidMime($logger, $response, self::$jsonMime)) {
            return ApiError::invalidMime();
        }

        $rawResponse = $response->getBody()->getContents();
        try {
            $result = json_decode(json: $rawResponse, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            $logger->error('Unable to decode JSON', ['error' => $error, 'json' => $rawResponse]);

            return ApiError::malformedJson();
        }

        return $result;
    }
}
