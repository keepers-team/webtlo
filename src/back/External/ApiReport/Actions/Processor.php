<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use KeepersTeam\Webtlo\External\Data\ApiError;
use KeepersTeam\Webtlo\External\Shared\Validation;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

trait Processor
{
    use Validation;

    /**
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

    /**
     * @return callable(GuzzleException, int): void
     */
    protected static function getChunkErrorHandler(LoggerInterface $logger, ?int $total = null): callable
    {
        return function(GuzzleException $error, int $index) use ($logger, $total): void {
            $logger->debug('Got unexpected error when fetch chunk {index}/{total}', [
                'index'   => $index,
                'total'   => $total ?? 'X',
                'message' => $error->getMessage(),
            ]);

            $logger->error('Failed to fetch chunk', ['code' => $error->getCode()]);
        };
    }
}
