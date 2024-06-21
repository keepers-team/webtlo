<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module;

use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\Legacy\Db;
use PDO;

/** Методы для работы с раздачами в торрент-клиентах. */
final class Torrents
{
    /**
     * Поиск в БД ид раздач, по хешу
     *
     * @param string[] $hashes
     * @param int      $chunkSize
     * @return array<string, int>
     */
    public static function getTopicsIdsByHashes(array $hashes, int $chunkSize = 500): array
    {
        $result = [];
        $hashes = array_chunk($hashes, $chunkSize);
        foreach ($hashes as $chunk) {
            $search = KeysObject::create($chunk);
            $topics = Db::query_database(
                "
                    SELECT info_hash, topic_id FROM Torrents
                    WHERE info_hash IN ($search->keys) AND topic_id <> ''
                ",
                $search->values,
                true,
                PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE
            );
            if (!empty($topics)) {
                $result[] = (array)$topics;
            }
        }

        return array_merge(...$result);
    }

    /**
     * Удалить раздачи в БД по хешу
     *
     * @param string[] $hashes
     * @param int      $chunkSize
     */
    public static function removeTorrents(array $hashes, int $chunkSize = 500): void
    {
        $hashes = array_chunk($hashes, $chunkSize);
        foreach ($hashes as $chunk) {
            $search = KeysObject::create($chunk);

            Db::query_database(
                "DELETE FROM Torrents WHERE info_hash IN ($search->keys)",
                $search->values
            );
        }
    }
}
