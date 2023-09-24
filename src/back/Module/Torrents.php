<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module;

use Db;
use PDO;
use KeepersTeam\Webtlo\DTO\KeysObject;

/** Методы для работы с раздачами в торрент-клиентах. */
final class Torrents
{
    /** Поиск в БД ид раздач, по хешу */
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
                $result[] = $topics;
            }
        }
        return array_merge(...$result);
    }
}