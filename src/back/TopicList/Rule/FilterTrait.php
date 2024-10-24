<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use RuntimeException;
use Throwable;

trait FilterTrait
{
    /**
     * Получить из БД список раздач и отсортировать по заданному фильтру.
     *
     * @param (int|string)[] $params
     *
     * @return array<string, mixed>[]
     */
    protected function selectTopics(string $statement, array $params = []): array
    {
        try {
            return $this->db->query($statement, $params);
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage());
        }
    }
}
