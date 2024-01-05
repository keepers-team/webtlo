<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use PDO;
use Exception;

trait DbHelperTrait
{
    /** Список подразделов с раздачами высокого приоритета. */
    public function getHighPriorityForums(): array
    {
        try {
            return $this->db->query(
                'SELECT DISTINCT ss FROM Topics WHERE pt = ?',
                [2],
                PDO::FETCH_COLUMN
            );
        } catch (Exception) {
            return [];
        }
    }

    public function queryStatementRow(string $statement, array $params = []): array
    {
        try {
            return (array)$this->db->queryRow($statement, $params);
        } catch (Exception) {
            return [];
        }
    }

    public function queryStatementGroup(string $statement, array $params = []): array
    {
        try {
            return $this->db->query($statement, $params, PDO::FETCH_ASSOC | PDO::FETCH_GROUP);
        } catch (Exception) {
            return [];
        }
    }
}