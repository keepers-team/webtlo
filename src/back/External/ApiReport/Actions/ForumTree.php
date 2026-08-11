<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\Utils;
use KeepersTeam\Webtlo\Data\Forum;
use KeepersTeam\Webtlo\DateHelper;
use KeepersTeam\Webtlo\External\Data\ApiError;
use KeepersTeam\Webtlo\External\Data\ForumsResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

trait ForumTree
{
    /**
     * Получить список подразделов форума.
     */
    public function getForums(): ForumsResponse|ApiError
    {
        $dataProcessor = self::getForumTreeProcessor($this->logger);

        $requests = [
            $this->client->getAsync('proxy_api/v1/static/cat_forum_tree'),
            $this->client->getAsync('proxy_api/v1/static/forum_size'),
        ];

        try {
            [$treeResponse, $sizeResponse] = Utils::unwrap($requests);
        } catch (GuzzleException $error) {
            $code = $error->getCode();

            $this->logger->warning('ForumTree. Ошибка получения данных', [$error->getMessage()]);

            return ApiError::fromHttpCode(code: $code);
        } catch (Throwable $error) {
            $this->logger->warning('ForumTree. Неизвестная ошибка получения данных', [$error->getMessage()]);

            // Just in case
            return ApiError::fromLegacyError(legacyError: null);
        }

        return $dataProcessor($treeResponse, $sizeResponse);
    }

    private static function getForumTreeProcessor(LoggerInterface $logger): callable
    {
        return function(
            ResponseInterface $treeResponse,
            ResponseInterface $sizeResponse
        ) use ($logger): ForumsResponse|ApiError {
            $treeResult = self::decodeResponse(logger: $logger, response: $treeResponse);
            if ($treeResult instanceof ApiError) {
                return $treeResult;
            }

            $sizeResult = self::decodeResponse(logger: $logger, response: $sizeResponse);
            if ($sizeResult instanceof ApiError) {
                return $sizeResult;
            }

            return self::parseStaticForumTree(trees: $treeResult, sizes: $sizeResult);
        };
    }

    /**
     * @param array<array-key, mixed> $trees
     * @param array<array-key, mixed> $sizes
     */
    private static function parseStaticForumTree(array $trees, array $sizes): ForumsResponse
    {
        $updateTime = DateHelper::makeFromTimestamp(min($trees['update_time'], $sizes['update_time']));

        /**
         * Категории форума - основные группы.
         *
         * @var string[] $categoryNames
         */
        $categoryNames = $trees['result']['c'];

        /**
         * Форумы - разделы и подразделы.
         * Входят в категории.
         *
         * Декодируем html-сущности, в частности - emoji.
         *
         * @var string[] $forumNames
         */
        $forumNames = array_map(
            static fn($name) => html_entity_decode(trim($name), ENT_QUOTES, 'UTF-8'),
            $trees['result']['f']
        );

        /**
         * Дерево категорий, разделов и подразделов.
         *
         * @var int[][][] $categoriesHierarchy
         */
        $categoriesHierarchy = $trees['result']['tree'];

        /**
         * Обработанный справочник разделов и подразделов.
         *
         * @var Forum[] $forums
         */
        $forums = [];

        /**
         * @param string[] $parts
         *
         * @return string[]
         */
        $hierarchyProcessor = static function(int $forumId, array $parts) use (&$forums, $forumNames, $sizes): array {
            $parts[] = $forumNames[$forumId];

            if (isset($sizes['result'][$forumId])) {
                [$count, $size] = $sizes['result'][$forumId];

                $forums[$forumId] = new Forum(
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
