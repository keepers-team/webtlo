<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use Throwable;
use RuntimeException;

trait FilterTrait
{
    /** Получить из БД список раздач и отсортировать по заданному фильтру. */
    protected function selectTopics(string $statement, array $params = []): array
    {
        try {
            return $this->db->query($statement, $params);
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage());
        }
    }
}
