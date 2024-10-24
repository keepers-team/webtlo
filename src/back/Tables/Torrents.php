<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Tables;

use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\DTO\KeysObject;
use PDO;

final class Torrents
{
    public function __construct(private readonly DB $db) {}

    /**
     * Поиск в БД ид раздач, по хешу
     *
     * @param string[] $hashes
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
     * Удалить раздачи в БД по хешу
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
}
