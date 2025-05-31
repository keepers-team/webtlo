<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage\Table;

use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\Storage\KeysObject;
use PDO;

final class Torrents
{
    public function __construct(private readonly DB $db) {}

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
                "
                    SELECT info_hash, topic_id FROM Torrents
                    WHERE info_hash IN ($search->keys) AND topic_id <> ''
                ",
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
     * Найти список раздач, сгруппировав их по ид клиента.
     *
     * @param string[] $hashes
     *
     * @return array<int, string[]>
     */
    public function getGroupedByClientTopics(array $hashes, int $chunkSize = 499): array
    {
        $result = [];

        $hashes = array_chunk(
            array_unique($hashes),
            max(1, $chunkSize)
        );

        foreach ($hashes as $chunk) {
            $search = KeysObject::create($chunk);

            $stm = $this->db->executeStatement(
                "
                    SELECT client_id, info_hash FROM Torrents
                    WHERE info_hash IN ($search->keys)
                ",
                $search->values,
            );

            $topics = $stm->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_COLUMN);

            foreach ($topics as $clientId => $clientHashes) {
                $clientId = (int) $clientId;

                $result[$clientId] = isset($result[$clientId])
                    ? array_merge($result[$clientId], $clientHashes)
                    : $clientHashes;
            }
        }

        return $result;
    }

    /**
     * Удалить раздачи в БД по хешу.
     *
     * @param string[] $hashes
     */
    public function deleteTorrentsByHashes(array $hashes): void
    {
        $hashes = array_chunk($hashes, 500);
        foreach ($hashes as $chunk) {
            $search = KeysObject::create($chunk);

            $this->db->executeStatement(
                "DELETE FROM Torrents WHERE info_hash IN ($search->keys)",
                $search->values
            );
        }
    }

    /**
     * Изменить статус раздач в БД по хешу.
     *
     * @param string[] $hashes
     */
    public function setTorrentsStatusByHashes(array $hashes, bool $paused): void
    {
        $paused = (int) $paused;

        $hashes = array_chunk($hashes, 500);
        foreach ($hashes as $chunk) {
            $search = KeysObject::create($chunk);

            $this->db->executeStatement(
                "UPDATE Torrents SET paused = ? WHERE info_hash IN ($search->keys)",
                [$paused, ...$search->values]
            );
        }
    }

    /**
     * @return array<int, array<string, int>>
     */
    public function getClientsTopics(): array
    {
        $query = '
            SELECT client_id,
                   COUNT(1) AS topics,
                   SUM(CASE WHEN done = 1 THEN 1 ELSE 0 END) AS done,
                   SUM(CASE WHEN done < 1 THEN 1 ELSE 0 END) AS downloading,
                   SUM(paused) AS paused, SUM(error) AS error
            FROM Torrents t
            GROUP BY client_id
            ORDER BY topics DESC
        ';

        return $this->db->query($query, [], PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);
    }
}
