<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\Actions;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\Utils;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\ForumDetails;
use KeepersTeam\Webtlo\External\Api\V1\ForumsResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

trait ForumTree
{
    use Processor;

    /** Получить список подразделов форума. */
    public function getForums(): ForumsResponse|ApiError
    {
        $dataProcessor = self::getForumProcessor($this->logger);

        $requests = [
            $this->client->getAsync('static/cat_forum_tree'),
            $this->client->getAsync('static/forum_size'),
        ];

        try {
            [$treeResponse, $sizeResponse] = Utils::unwrap($requests);
        } catch (GuzzleException $error) {
            $code = $error->getCode();

            $this->logger->warning('ForumTree. Ошибка получения данных', [$error->getMessage()]);

            return ApiError::fromHttpCode($code);
        } catch (Throwable $error) {
            $this->logger->warning('ForumTree. Неизвестная ошибка получения данных', [$error->getMessage()]);

            // Just in case
            return ApiError::fromLegacyError(legacyError: null);
        }

        return $dataProcessor($treeResponse, $sizeResponse);
    }

    private static function getForumProcessor(LoggerInterface $logger): callable
    {
        return function(
            ResponseInterface $treeResponse,
            ResponseInterface $sizeResponse
        ) use (&$logger): ForumsResponse|ApiError {
            $treeResult = self::decodeResponse($logger, $treeResponse);
            if ($treeResult instanceof ApiError) {
                return $treeResult;
            }

            $sizeResult = self::decodeResponse($logger, $sizeResponse);
            if ($sizeResult instanceof ApiError) {
                return $sizeResult;
            }

            return self::parseStaticForumTree($treeResult, $sizeResult);
        };
    }

    /**
     * @param array<string, mixed> $trees
     * @param array<string, mixed> $sizes
     * @return ForumsResponse
     */
    private static function parseStaticForumTree(array $trees, array $sizes): ForumsResponse
    {
        $updateTime = self::dateTimeFromTimestamp(min($trees['update_time'], $sizes['update_time']));

        /** @var ForumDetails[] $forums */
        $forums = [];
        /** @var int[][][] $categoriesHierarchy */
        $categoriesHierarchy = $trees['result']['tree'];
        /** @var string[] $categoryNames */
        $categoryNames = $trees['result']['c'];
        /** @var string[] $forumNames */
        $forumNames = $trees['result']['f'];

        $hierarchyProcessor = function(int $forumId, array $parts) use (&$forums, $forumNames, $sizes): array {
            $parts[] = $forumNames[$forumId];

            if (isset($sizes['result'][$forumId])) {
                [$count, $size] = $sizes['result'][$forumId];

                $forums[] = new ForumDetails(
                    id   : $forumId,
                    name : implode(' » ', $parts),
                    count: $count,
                    size : $size
                );
            }

            return $parts;
        };

        foreach ($categoriesHierarchy as $categoryId => $forumsHierarchy) {
            $categoryName = $categoryNames[$categoryId];

            foreach ($forumsHierarchy as $forumId => $subForumsHierarchy) {
                $nameParts = $hierarchyProcessor($forumId, [$categoryName]);

                foreach ($subForumsHierarchy as $subForumId) {
                    $hierarchyProcessor($subForumId, $nameParts);
                }
            }
        }

        return new ForumsResponse(
            updateTime: $updateTime,
            forums    : $forums
        );
    }
}
