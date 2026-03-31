<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\Actions;

use KeepersTeam\Webtlo\External\Api\V1\TopicSearchMode;
use Throwable;

trait RequestLimit
{
    /**
     * @var ?positive-int
     */
    private static ?int $apiParamsLimit = null;

    /**
     * Returns the params limit based on the search mode and API limit.
     *
     * @return positive-int
     */
    protected function getParamsLimit(TopicSearchMode $searchMode): int
    {
        if (self::$apiParamsLimit === null) {
            self::$apiParamsLimit = $this->getApiParamsLimit();
        }

        return min(self::$apiParamsLimit, $searchMode->paramsLimit());
    }

    /**
     * Try load request limit form API itself.
     *
     * Use known value on fail.
     *
     * @return positive-int
     */
    private function getApiParamsLimit(): int
    {
        try {
            $response = $this->client->get(uri: 'get_limit');

            $result = self::decodeResponse(logger: $this->logger, response: $response);

            if (is_array($result) && !empty($result['result']['limit'])) {
                return max(1, (int) $result['result']['limit']);
            }
        } catch (Throwable) {
        }

        return TopicSearchMode::ID->paramsLimit();
    }
}
