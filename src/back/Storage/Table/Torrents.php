<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage\Table;

use KeepersTeam\Webtlo\Infrastructure\Database\ConnectionInterface;
use KeepersTeam\Webtlo\Storage\KeysObject;
use PDO;

final class Torrents
{
    public function __construct(private readonly ConnectionInterface $con) {}

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

            $stm = $this->con->executeStatement(
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
     * Найти список раздач, сгруппировав их по ид клиента и ид подраздела.
     *
     * @param string[] $hashes
     *
     * @return array<int, array<int, string[]>>
     */
    public function getGroupedTopics(array $hashes): array
    {
        $result = [];

        $hashes = array_chunk(array_unique($hashes), 500);

        foreach ($hashes as $chunk) {
            $search = KeysObject::create($chunk);
            $query  = "
                SELECT tr.client_id, tp.forum_id, tr.info_hash
                FROM Torrents AS tr
                    LEFT JOIN Topics AS tp ON tp.info_hash = tr.info_hash
                WHERE tr.info_hash IN ($search->keys)
            ";

            $topics = $this->con->query($query, $search->values);
            foreach ($topics as $topic) {
                $clientId = (int) $topic['client_id'];
                $forumId  = (int) $topic['forum_id'];

                $result[$clientId][$forumId][] = $topic['info_hash'];
            }
        }

        return $result;
    }

    /**
     * Найти хеши предыдущих версий из группы «обновлено (...)» для этого клиента.
     *
     * @param string[] $hashes хеши актуальных версий
     *
     * @return array<string, string[]> хеши предыдущих версий по хешу актуальной
     */
    public function getUpdatedPreviousHashes(array $hashes, int $clientId): array
    {
        $result = [];
        foreach (array_chunk(array_unique($hashes), 500) as $chunk) {
            $search = KeysObject::create($chunk);
            $rows   = $this->con->query(
                "
                    SELECT current.info_hash AS current_hash, previous.info_hash AS previous_hash
                    FROM Topics AS current
                    INNER JOIN Torrents AS previous ON previous.topic_id = current.id
                    INNER JOIN TopicsUnregistered AS unregistered ON unregistered.info_hash = previous.info_hash
                    WHERE current.info_hash IN ($search->keys)
                      AND previous.client_id = ?
                      AND previous.info_hash <> current.info_hash
                      AND unregistered.status LIKE 'обновлено (%'
                ",
                [...$search->values, $clientId],
            );

            foreach ($rows as $row) {
                $result[$row['current_hash']][] = $row['previous_hash'];
            }
        }

        return $result;
    }

    /**
     * @param string[]     $hashes
     * @param positive-int $chunkSize
     */
    public function addDownloadedTorrents(array $hashes, int $clientId, int $chunkSize = 500): void
    {
        $chunks = array_chunk($hashes, $chunkSize);
        foreach ($chunks as $chunk) {
            $object = KeysObject::create($chunk);

            $sql = "
                INSERT INTO Torrents (
                    info_hash,
                    client_id,
                    topic_id,
                    name,
                    total_size
                )
                SELECT
                    Topics.info_hash,
                    ?,
                    Topics.id,
                    Topics.name,
                    Topics.size
                FROM Topics
                WHERE info_hash IN ($object->keys)
            ";

            $this->con->executeStatement(
                sql  : $sql,
                param: [$clientId, ...$object->values],
            );
        }
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

            $this->con->executeStatement(
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

            $this->con->executeStatement(
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

        return $this->con->query($query, [], PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);
    }
}
