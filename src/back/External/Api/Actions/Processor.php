<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\Actions;

use DateTimeImmutable;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\Promise;
use JsonException;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\TopicSearchMode;
use KeepersTeam\Webtlo\External\Validation;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

trait Processor
{
    use Validation;

    protected static function dateTimeFromTimestamp(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable())->setTimestamp($timestamp);
    }

    protected static function getRequestLimit(TopicSearchMode $searchMode): int
    {
        return match ($searchMode) {
            TopicSearchMode::ID   => 100,
            /**
             * Hashes are longer, so to avoid HTTP 414 in legacy API
             * we're capping max identifiers per request
             */
            TopicSearchMode::HASH => 32,
        };
    }

    protected static function getChunkErrorHandler(LoggerInterface $logger): callable
    {
        return function(GuzzleException $error, int $index, Promise $aggregatePromise) use (&$logger): void {
            $logger->debug('Got unexpected error when fetch chunk', [
                'index'   => $index,
                'message' => $error->getMessage(),
            ]);

            $code = $error->getCode();
            $logger->error('Failed to fetch chunk', ['code' => $code]);
            $aggregatePromise->reject(ApiError::fromHttpCode($code));
        };
    }

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

        if (array_key_exists('error', $result) || !array_key_exists('result', $result)) {
            $logger->warning('Invalid result', ['json' => $rawResponse]);

            return ApiError::fromLegacyError(legacyError: $result['error'] ?? null);
        } else {
            return $result;
        }
    }
}
