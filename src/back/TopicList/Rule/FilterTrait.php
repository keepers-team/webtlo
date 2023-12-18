<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\TopicList\DbHelper;
use KeepersTeam\Webtlo\TopicList\Filter\Sort;

trait FilterTrait
{
    /** Получить из БД список раздач и отсортировать по заданному фильтру. */
    protected function selectSortedTopics(Sort $sort, string $statement, array $params = []): array
    {
        $topics = DbHelper::queryStatement($statement, $params);

        return Helper::natsortField(
            $topics,
            $sort->rule->value,
            $sort->direction->value
        );
    }
}