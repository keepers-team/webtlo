<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage\Table;

use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\Storage\KeysObject;
use PDO;

/** Таблица с данным о раздачах. */
final class Topics
{
    // Параметры таблицы.
    public const TABLE   = 'Topics';
    public const PRIMARY = 'id';
    public const KEYS    = [
        self::PRIMARY,
        'forum_id',
        'name',
        'info_hash',
        'seeders',
        'size',
        'status',
        'reg_time',
        'seeders_updates_today',
        'seeders_updates_days',
        'keeping_priority',
        'poster',
        'seeder_last_seen',
    ];

    public function __construct(private readonly DB $db) {}

    /** Сколько раздач без названия. */
    public function countUnnamed(): int
    {
        return $this->db->queryCount("SELECT COUNT(1) FROM Topics WHERE name IS NULL OR name = ''");
    }

    /**
     * Выбрать N раздач без названия.
     *
     * @return int[]
     */
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

    /**
     * Поиск существующих сведений о раздачах.
     *
     * @param int[] $topicIds
     *
     * @return array<int, array<string, int|string>>
     */
    public function searchPrevious(array $topicIds): array
    {
        $selectTopics = KeysObject::create($topicIds);

        return $this->db->query(
            "
                SELECT id, info_hash, reg_time, seeders, seeders_updates_today, seeders_updates_days, poster, name
                FROM Topics
                WHERE id IN ($selectTopics->keys)
            ",
            $selectTopics->values,
            PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE
        );
    }

    /**
     * Поиск в БД ид раздач, по хешу.
     *
     * @param string[] $hashes
     *
     * @return array<string, array{topic_id:int}>
     */
    public function getTopicsIdsByHashes(array $hashes, int $chunkSize = 500): array
    {
        $result = [];

        $hashes = array_chunk($hashes, max(1, $chunkSize));
        foreach ($hashes as $chunk) {
            $search = KeysObject::create($chunk);

            $stm = $this->db->executeStatement(
                "SELECT info_hash, id AS topic_id FROM Topics WHERE info_hash IN ($search->keys)",
                $search->values,
            );

            $topics = $stm->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);

            if (!empty($topics)) {
                $result[] = $topics;
            }
        }

        return array_merge(...$result);
    }

    /**
     * Удаление раздач по списку их ИД.
     *
     * @param int[] $topics
     */
    public function deleteTopicsByIds(array $topics): void
    {
        $chunks = array_chunk(array_unique($topics), 500);
        foreach ($chunks as $chunk) {
            $delete = KeysObject::create($chunk);

            $this->db->executeStatement(
                "DELETE FROM Topics WHERE id IN ($delete->keys)",
                $delete->values
            );
        }
    }

    /**
     * Удаление раздач из подразделов,
     * для которых нет актуальных меток обновления.
     */
    public function removeOutdatedRows(): void
    {
        $query = '
            DELETE FROM Topics
            WHERE forum_id NOT IN (SELECT id FROM UpdateTime WHERE id < 100000)
        ';

        $this->db->executeStatement(sql: $query);
    }
}
