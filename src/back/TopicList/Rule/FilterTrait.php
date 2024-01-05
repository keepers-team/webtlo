<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\TopicList\Filter\Sort;
use Exception;

trait FilterTrait
{
    /** Получить из БД список раздач и отсортировать по заданному фильтру. */
    protected function selectSortedTopics(Sort $sort, string $statement, array $params = []): array
    {
        try {
            $topics = $this->db->query($statement, $params);
        } catch (Exception) {
            $topics = [];
        }

        return Helper::natsortField(
            $topics,
            $sort->rule->value,
            $sort->direction->value
        );
    }
}