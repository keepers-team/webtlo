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
     * Resolve checked rows against the current database state. A hash can exist in several clients.
     *
     * @param array{hash: string, client_id: int}[] $selected
     *
     * @return array<int, array{old_hash: string, client_id: int, topic_id: int, current_hash: ?string, added_hash: ?string}>
     */
    public function getSelectedUnregistered(array $selected): array
    {
        $result = [];
        foreach (array_chunk($selected, 400) as $chunk) {
            $pairs  = implode(', ', array_fill(0, count($chunk), '(?, ?)'));
            $params = [];
            foreach ($chunk as $row) {
                $params[] = $row['hash'];
                $params[] = $row['client_id'];
            }

            $rows = $this->con->query(
                "
                    SELECT tr.info_hash AS old_hash, tr.client_id, tr.topic_id,
                           current.info_hash AS current_hash, added.info_hash AS added_hash
                    FROM Torrents AS tr
                    INNER JOIN TopicsUnregistered AS unregistered ON unregistered.info_hash = tr.info_hash
                    LEFT JOIN Topics AS current ON current.id = tr.topic_id
                    LEFT JOIN Torrents AS added
                        ON added.info_hash = current.info_hash AND added.client_id = tr.client_id
                    WHERE (tr.info_hash, tr.client_id) IN ($pairs)
                ",
                $params,
            );

            array_push($result, ...$rows);
        }

        return $result;
    }

    public function insertAddedTopic(string $hash, int $clientId, int $topicId, string $name, int $size): void
    {
        $this->con->executeStatement(
            '
                INSERT OR IGNORE INTO Torrents (info_hash, client_id, topic_id, name, total_size)
                VALUES (?, ?, ?, ?, ?)
            ',
            [$hash, $clientId, $topicId, $name, $size],
        );
    }

    /**
     * @param array{hash: string, client_id: int}[] $topics
     *
     * @return array<string, true> keys in the form client_id:UPPER(info_hash)
     */
    public function getExistingClientHashes(array $topics): array
    {
        $existing = [];
        foreach (array_chunk($topics, 400) as $chunk) {
            $pairs  = implode(', ', array_fill(0, count($chunk), '(?, ?)'));
            $params = [];
            foreach ($chunk as $topic) {
                $params[] = $topic['hash'];
                $params[] = $topic['client_id'];
            }

            $rows = $this->con->query(
                "SELECT info_hash, client_id FROM Torrents WHERE (info_hash, client_id) IN ($pairs)",
                $params,
            );
            foreach ($rows as $row) {
                $existing[$row['client_id'] . ':' . strtoupper($row['info_hash'])] = true;
            }
        }

        return $existing;
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
