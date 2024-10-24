<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use Exception;
use PDO;
use RuntimeException;

trait DbHelperTrait
{
    /**
     * Список подразделов с раздачами высокого приоритета.
     *
     * @return int[]
     */
    public function getHighPriorityForums(): array
    {
        try {
            return $this->db->query(
                'SELECT DISTINCT forum_id FROM Topics WHERE keeping_priority = ?',
                [2],
                PDO::FETCH_COLUMN
            );
        } catch (Exception $e) {
            throw new RuntimeException($e->getMessage());
        }
    }

    /**
     * @param (int|string)[] $params
     *
     * @return array<int, mixed>[]|array<never>
     */
    public function queryStatement(string $statement, array $params = []): array
    {
        try {
            return $this->db->query($statement, $params);
        } catch (Exception $e) {
            throw new RuntimeException($e->getMessage());
        }
    }

    /**
     * @param (int|string)[] $params
     *
     * @return array<int, mixed>|array<never>
     */
    public function queryStatementRow(string $statement, array $params = []): array
    {
        try {
            return (array) $this->db->queryRow($statement, $params);
        } catch (Exception $e) {
            throw new RuntimeException($e->getMessage());
        }
    }

    /**
     * @param (int|string)[] $params
     *
     * @return array<int|string, mixed>[]|array<never>
     */
    public function queryStatementGroup(string $statement, array $params = []): array
    {
        try {
            return $this->db->query($statement, $params, PDO::FETCH_ASSOC | PDO::FETCH_GROUP);
        } catch (Exception $e) {
            throw new RuntimeException($e->getMessage());
        }
    }
}
