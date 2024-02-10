<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Tables;

use KeepersTeam\Webtlo\DB;
use PDO;

final class Topics
{
    public function __construct(private readonly DB $db)
    {
    }

    /** Сколько раздач без названия. */
    public function countUnnamed(): int
    {
        return $this->db->queryCount("SELECT COUNT(1) FROM Topics WHERE name IS NULL OR name = ''");
    }

    /** Выбрать N раздач без названия. */
    public function getUnnamedTopics(int $limit = 5000): array
    {
        return $this->db->query(
            "SELECT id FROM Topics WHERE name IS NULL OR name = '' LIMIT ?",
            [$limit],
            PDO::FETCH_COLUMN
        );
    }

    /** Сколько всего раздач в таблице. */
    public function countTotal(): int
    {
        return $this->db->selectRowsCount('Topics');
    }
}
